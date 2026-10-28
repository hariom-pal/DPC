<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Csc;

use App\Integrations\Uidai\UidaiSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Posts one UIDAI document to the CSC authentication REST API and returns its
 * envelope. Ported from the CGMFPFED Welfare Platform (`CscAuthClient`) without
 * its wire-capture diagnostics: nothing here writes a request or a response
 * anywhere, because the request carries the Aadhaar number.
 *
 * Knows nothing about UIDAI beyond "the payload is a string".
 *
 * **A UIDAI request is not idempotent.** Error 563 refuses an identical resent
 * authentication, and an OTP request re-sent texts the officer again. So the
 * only thing ever retried is a request whose response was never seen (connect
 * failure, timeout, 5xx), and only `retryAttempts` times — 0 by default.
 *
 * Logs carry the `txn` and CSC codes only — never the Aadhaar, the OTP, the
 * body or the token.
 */
final readonly class CscAuthClient
{
    private CscEnvelopeCodec $codec;

    public function __construct(
        private HttpFactory $http,
        private UidaiSettings $settings,
    ) {
        $this->codec = new CscEnvelopeCodec($settings->authToken);
    }

    /**
     * @throws CscTransportException CSC unreachable, no answer in time, or not a CSC envelope
     * @throws CscRejectedException CSC answered with a code other than "10"
     */
    public function send(string $uidaiXml, string $txn): CscResponseEnvelope
    {
        $data = $this->codec->encodeData($uidaiXml);

        // Key order is the working reference implementation's. JSON order means
        // nothing to a parser, but the goal is the same bytes CSC already accepts.
        $payload = [
            'client_id' => $this->settings->clientId,
            'req_hash' => $this->codec->hash($data),
            'req_data' => $data,
            'client_ts' => $this->codec->timestamp(),
        ];

        $envelope = $this->post($payload, $txn);

        if (! $envelope->wasProcessed()) {
            throw new CscRejectedException($envelope->responseCode, $envelope->responseMessage);
        }

        $this->checkResponseIntegrity($envelope, $txn);

        return $envelope;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function post(array $payload, string $txn): CscResponseEnvelope
    {
        $url = $this->settings->baseUrl.'/'.$this->settings->endpoint;
        $attempts = max(1, $this->settings->retryAttempts + 1);
        $lastError = 'no attempt was made';

        /*
         * Encoded here rather than by the HTTP client for JSON_UNESCAPED_SLASHES:
         * `req_data` and `req_hash` are base64 and contain "/", which the client's
         * default encoding would write as "\/" — a different body from the one the
         * working implementation sends.
         */
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http
                    ->timeout($this->settings->timeoutSeconds)
                    ->connectTimeout(min(10, $this->settings->timeoutSeconds))
                    ->acceptJson()
                    ->withHeaders(['authToken' => $this->settings->authToken])
                    ->withBody($body, 'application/json')
                    ->post($url);
            } catch (ConnectionException) {
                // Connection refused, DNS failure, timeout — the response was never seen.
                // The exception text is not kept: it can echo the URL and options.
                $lastError = 'connection failed or timed out';
                $this->logRetry($txn, $attempt, $attempts, $lastError);
                $this->backoff($attempt, $attempts);

                continue;
            }

            if ($response->serverError()) {
                $lastError = 'HTTP '.$response->status();
                $this->logRetry($txn, $attempt, $attempts, $lastError);
                $this->backoff($attempt, $attempts);

                continue;
            }

            if ($response->failed()) {
                // 4xx is our fault and fails identically next time. Never retried.
                throw new CscTransportException('CSC refused the request at the transport level with HTTP '.$response->status().'.');
            }

            $decoded = json_decode($response->body(), true);

            if (! is_array($decoded)) {
                throw new CscTransportException('CSC returned a body that is not JSON.');
            }

            return CscResponseEnvelope::fromArray($decoded);
        }

        throw new CscTransportException(sprintf('CSC could not be reached after %d attempt(s): %s.', $attempts, $lastError));
    }

    /**
     * `res_hash` against `res_data`. Enforced when `verify_response_hash` is on
     * (the default); otherwise a mismatch is only logged. Absent hashes are not
     * refused — CSC's error envelopes carry none.
     */
    private function checkResponseIntegrity(CscResponseEnvelope $envelope, string $txn): void
    {
        if ($envelope->hash === null || $envelope->data === null || $this->codec->verify($envelope->data, $envelope->hash)) {
            return;
        }

        Log::warning('UIDAI/CSC response hash did not match its payload.', [
            'txn' => $txn,
            'enforced' => $this->settings->verifyResponseHash,
        ]);

        if ($this->settings->verifyResponseHash) {
            throw new CscTransportException('The CSC response failed its integrity check: res_data does not match res_hash.');
        }
    }

    /** Exponential with jitter, and only when another attempt will follow. */
    private function backoff(int $attempt, int $attempts): void
    {
        if ($attempt < $attempts) {
            usleep((int) ((2 ** ($attempt - 1)) * 500_000 + random_int(0, 200_000)));
        }
    }

    private function logRetry(string $txn, int $attempt, int $attempts, string $error): void
    {
        Log::warning('CSC authentication API attempt failed.', [
            'txn' => $txn,
            'attempt' => $attempt,
            'of' => $attempts,
            'error' => $error,
        ]);
    }
}
