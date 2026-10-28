<?php

declare(strict_types=1);

namespace App\Integrations\Uidai;

use App\Integrations\Aadhaar\AadhaarOtpRequest;
use App\Integrations\Aadhaar\AadhaarOtpVerification;
use App\Integrations\Contracts\AadhaarOtpProvider;
use App\Integrations\Uidai\Auth\AuthResponseParser;
use App\Integrations\Uidai\Auth\AuthXmlBuilder;
use App\Integrations\Uidai\Auth\UidaiErrorCatalogue;
use App\Integrations\Uidai\Csc\CscAuthClient;
use App\Integrations\Uidai\Csc\CscRejectedException;
use App\Integrations\Uidai\Csc\CscResponseEnvelope;
use App\Integrations\Uidai\Csc\CscTransportException;
use App\Integrations\Uidai\Otp\OtpPidBlockBuilder;
use App\Integrations\Uidai\Otp\OtpRequestBuilder;
use App\Integrations\Uidai\Otp\OtpResponseParser;
use App\Integrations\Uidai\Otp\TransactionId;
use App\Integrations\Uidai\Support\AadhaarFormat;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * UIDAI Aadhaar OTP **authentication** (yes/no — not e-KYC) over the CSC
 * authentication REST API. Ported from the CGMFPFED Welfare Platform's
 * `CscOtpKycAuthenticator` and the UIDAI/CSC classes beneath it, with the e-KYC
 * wrapper, biometric, RD-device, wire-capture and web-gateway pieces dropped.
 *
 * Two legs, one `txn`:
 *
 *  1. `requestOtp()` — OTP Request API 2.5 `<Otp>`: UIDAI texts a code to the
 *     mobile registered against the Aadhaar. Proves nothing about the officer.
 *  2. `verifyOtp()` — Authentication API 2.5 `<Auth>` with an OTP-only PID block
 *     (`Uses otp="y" bio="n"`, `tid=""`, Meta "NA"), encrypted here with UIDAI's
 *     public encryption certificate. UIDAI answers `ret="y"` or `ret="n"`; no
 *     identity is requested and none is read.
 *
 * Both legs travel inside the same CSC envelope (`client_id`, `req_hash`,
 * `req_data`, `client_ts`) to the same endpoint.
 *
 * ---------------------------------------------------------------------------
 * FAILURE POLICY: THESE METHODS DO NOT THROW
 *
 * Every failure becomes `sent: false` / `verified: false` with a sentence the
 * officer can read and an `errorCode` support can trace:
 *
 *   UIDAI error code (e.g. 400, 952, 998)  UIDAI answered no; message from {@see UidaiErrorCatalogue}
 *   CSC-<res_code>                         CSC answered but did not forward/process (res_code ≠ 10)
 *   CSC-TRANSPORT                          CSC unreachable, timed out, 4xx/5xx, or hash mismatch
 *   UIDAI-PROTOCOL                         the response could not be read, or was for another txn
 *   CONFIG                                 a setting is missing or unusable (logged at ERROR)
 *   INVALID_AADHAAR / INVALID_OTP / INVALID_TXN   refused locally, nothing was sent
 *
 * The caller (`RegistrationVerificationService`) catches nothing, and a 500 on
 * the registration page tells the officer less than "the service is
 * unavailable". Deployment faults are still loud — they are logged at `error`.
 *
 * ---------------------------------------------------------------------------
 * THE AADHAAR NUMBER AND THE OTP
 *
 * Never logged, never stored, never put in an exception message. Logs carry the
 * `txn`, CSC/UIDAI codes and nothing else. Parameters are #[SensitiveParameter]
 * so neither can surface in a stack trace.
 *
 * ---------------------------------------------------------------------------
 * TIMESTAMPS
 *
 * UIDAI `ts` (in `<Otp>` and in `<Pid>`) is IST with NO offset — the spec
 * defaults an unqualified time to IST and rejects a request 20 minutes stale,
 * so a UTC server sending its own offset is refused. One `ts` is generated per
 * authentication and used for the Pid, its encryption (nonce/AAD) and its
 * prefix; a second clock read would yield a block UIDAI cannot decrypt.
 * CSC's `client_ts` is a different field and DOES carry "+05:30".
 */
class UidaiAadhaarOtpProvider implements AadhaarOtpProvider
{
    private const UNAVAILABLE = 'Aadhaar verification is temporarily unavailable. Please try again later or contact the helpdesk.';

    private readonly CscAuthClient $client;

    private readonly OtpRequestBuilder $otpBuilder;

    private readonly OtpResponseParser $otpParser;

    private readonly OtpPidBlockBuilder $pidBuilder;

    private readonly AuthXmlBuilder $authBuilder;

    private readonly AuthResponseParser $authParser;

    private readonly UidaiErrorCatalogue $errors;

    public function __construct(
        private readonly UidaiSettings $settings,
        HttpFactory $http,
    ) {
        $this->client = new CscAuthClient($http, $settings);
        $this->otpBuilder = new OtpRequestBuilder($settings);
        $this->otpParser = new OtpResponseParser;
        $this->pidBuilder = new OtpPidBlockBuilder($settings->encryptionCertificatePath);
        $this->authBuilder = new AuthXmlBuilder($settings);
        $this->authParser = new AuthResponseParser;
        $this->errors = new UidaiErrorCatalogue;
    }

    /** Never throws: settings are checked when a request is about to be sent. */
    public static function fromConfig(): self
    {
        return new self(UidaiSettings::fromConfig(), app(HttpFactory::class));
    }

    public function name(): string
    {
        return 'uidai';
    }

    public function requestOtp(#[SensitiveParameter] string $aadhaar): AadhaarOtpRequest
    {
        $aadhaar = AadhaarFormat::normalise($aadhaar);

        if (! AadhaarFormat::isValid($aadhaar)) {
            return new AadhaarOtpRequest(false, '', 'Enter a valid 12-digit Aadhaar number.', 'INVALID_AADHAAR');
        }

        if (($problems = $this->settings->problems(forVerification: false)) !== []) {
            $this->logConfiguration('request', null, $problems);

            return new AadhaarOtpRequest(false, '', self::UNAVAILABLE, 'CONFIG');
        }

        $txn = TransactionId::generate($this->settings->txnPrefix);

        try {
            $xml = $this->otpBuilder->build($aadhaar, $txn, $this->uidaiTimestamp());
            $response = $this->otpParser->parse($this->answer($this->client->send($xml, $txn)), $txn);
        } catch (CscRejectedException|CscTransportException|UidaiProtocolException|UidaiConfigurationException $e) {
            [$message, $code] = $this->failure('request', $txn, $e);

            return new AadhaarOtpRequest(false, $txn, $message, $code);
        }

        $this->logOutcome('request', $txn, $response->sent, $response->errorCode);

        return $response->sent
            ? new AadhaarOtpRequest(true, $txn, 'An OTP has been sent to the mobile number registered with this Aadhaar.')
            : new AadhaarOtpRequest(false, $txn, $this->errors->messageFor($response->errorCode), $response->errorCode);
    }

    public function verifyOtp(#[SensitiveParameter] string $aadhaar, string $txn, #[SensitiveParameter] string $otp): AadhaarOtpVerification
    {
        $aadhaar = AadhaarFormat::normalise($aadhaar);
        $otp = trim($otp);

        if (! AadhaarFormat::isValid($aadhaar)) {
            return new AadhaarOtpVerification(false, 'Enter a valid 12-digit Aadhaar number.', 'INVALID_AADHAAR');
        }

        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            return new AadhaarOtpVerification(false, 'The Aadhaar OTP must be the six digits sent by UIDAI.', 'INVALID_OTP');
        }

        // Only a txn this provider issued: a MOCK txn left over from a feature
        // switch would cost a UIDAI transaction to be refused with 402.
        if (! $this->isOwnTransaction($txn)) {
            return new AadhaarOtpVerification(false, 'This OTP request is no longer valid. Request a new OTP.', 'INVALID_TXN');
        }

        if (($problems = $this->settings->problems(forVerification: true)) !== []) {
            $this->logConfiguration('verify', $txn, $problems);

            return new AadhaarOtpVerification(false, self::UNAVAILABLE, 'CONFIG');
        }

        try {
            // ONE timestamp: the Pid, its GCM nonce/AAD and its prefix must agree.
            $pid = $this->pidBuilder->build($otp, $this->uidaiTimestamp());
            $xml = $this->authBuilder->build($aadhaar, $txn, $pid);
            $response = $this->authParser->parse($this->answer($this->client->send($xml, $txn)), $txn);
        } catch (CscRejectedException|CscTransportException|UidaiProtocolException|UidaiConfigurationException $e) {
            [$message, $code] = $this->failure('verify', $txn, $e);

            return new AadhaarOtpVerification(false, $message, $code);
        }

        $this->logOutcome('verify', $txn, $response->authenticated, $response->errorCode);

        return $response->authenticated
            ? new AadhaarOtpVerification(true, 'Aadhaar OTP verified.')
            : new AadhaarOtpVerification(false, $this->errors->messageFor($response->errorCode), $response->errorCode ?? 'UIDAI-N');
    }

    /** The UIDAI XML inside a processed envelope. */
    private function answer(CscResponseEnvelope $envelope): string
    {
        $xml = $envelope->decodedData();

        if ($xml === null) {
            throw new UidaiProtocolException('CSC reported the request as processed but res_data is not readable base64.');
        }

        return $xml;
    }

    /** IST, no offset — OTP API / Auth API 2.5 §3.3.1. */
    private function uidaiTimestamp(): string
    {
        return Carbon::now('Asia/Kolkata')->format('Y-m-d\TH:i:s');
    }

    private function isOwnTransaction(string $txn): bool
    {
        if (! str_starts_with($txn, $this->settings->txnPrefix)) {
            return false;
        }

        try {
            TransactionId::assertAcceptable($txn);
        } catch (UidaiProtocolException) {
            return false;
        }

        return true;
    }

    /**
     * The officer's sentence and the trace code for a failure that is not a
     * UIDAI "no". Exception messages are written never to carry the Aadhaar
     * or the OTP, so they are safe to log.
     *
     * @return array{0: string, 1: string}
     */
    private function failure(string $leg, string $txn, CscRejectedException|CscTransportException|UidaiProtocolException|UidaiConfigurationException $e): array
    {
        // Our own messages never carry the number, but CSC's res_msg is foreign
        // text: any twelve-digit run is masked before it can reach a log.
        $context = ['leg' => $leg, 'txn' => $txn, 'reason' => (string) preg_replace('/\d{12}/', '[redacted]', $e->getMessage())];

        return match (true) {
            $e instanceof CscRejectedException => $this->logged('warning', $context, [
                'The Aadhaar verification service could not process the request (CSC code '.$e->responseCode.'). Please try again later.',
                'CSC-'.$e->responseCode,
            ]),
            $e instanceof CscTransportException => $this->logged('warning', $context, [
                'The Aadhaar verification service could not be reached. Please try again shortly.',
                'CSC-TRANSPORT',
            ]),
            $e instanceof UidaiConfigurationException => $this->logged('error', $context, [self::UNAVAILABLE, 'CONFIG']),
            default => $this->logged('warning', $context, [
                'The Aadhaar verification service returned an unexpected response. Please try again.',
                'UIDAI-PROTOCOL',
            ]),
        };
    }

    /**
     * @param  array<string, string>  $context
     * @param  array{0: string, 1: string}  $result
     * @return array{0: string, 1: string}
     */
    private function logged(string $level, array $context, array $result): array
    {
        Log::log($level, 'UIDAI Aadhaar OTP call failed.', $context + ['code' => $result[1]]);

        return $result;
    }

    private function logOutcome(string $leg, string $txn, bool $ok, ?string $errorCode): void
    {
        $level = $ok ? 'info' : ($this->errors->isOurFault($errorCode) ? 'error' : 'notice');

        Log::log($level, 'UIDAI Aadhaar OTP answered.', ['leg' => $leg, 'txn' => $txn, 'ok' => $ok, 'err' => $errorCode]);
    }

    /** @param  list<string>  $problems */
    private function logConfiguration(string $leg, ?string $txn, array $problems): void
    {
        Log::error('UIDAI Aadhaar OTP is enabled but not configured.', ['leg' => $leg, 'txn' => $txn, 'problems' => $problems]);
    }
}
