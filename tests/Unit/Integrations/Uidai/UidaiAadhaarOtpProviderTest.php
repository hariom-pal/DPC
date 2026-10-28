<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Uidai;

use App\Integrations\Uidai\Csc\CscEnvelopeCodec;
use App\Integrations\Uidai\UidaiAadhaarOtpProvider;
use App\Integrations\Uidai\UidaiSettings;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The live UIDAI Aadhaar OTP provider, end to end against a faked CSC endpoint.
 *
 * No real network call is possible: `preventStrayRequests()` turns any request
 * the fake does not answer into an exception. The faked CSC answers with real
 * envelope shapes (`res_code`, `res_data` = base64 UIDAI XML, `res_hash` built
 * with the same codec) and, for the authentication leg, decrypts the PID block
 * with the private half of a throwaway certificate generated here — so the
 * encryption is checked for internal consistency, not merely for presence.
 */
class UidaiAadhaarOtpProviderTest extends TestCase
{
    /** Valid Verhoeff, starts 2-9: a well-formed number that belongs to nobody. */
    private const AADHAAR = '234567890124';

    private const OTP = '482913';

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    private const URL = 'https://csc.test/aua/springboot/auth/req';

    private string $certificatePath;

    private \OpenSSLAsymmetricKey $privateKey;

    private int $certificateExpiry;

    /** @var list<array{xml: string, envelope: array<string, mixed>, token: string}> */
    private array $sent = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        [$this->certificatePath, $this->privateKey, $this->certificateExpiry] = $this->throwawayCertificate();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->certificatePath);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ OTP request

    public function test_otp_request_success_returns_the_txn_and_sends_a_well_formed_otp_document(): void
    {
        $this->fakeCsc(fn (string $txn): string => '<?xml version="1.0"?><OtpRes ret="y" code="9f8e7d6c5b4a39281706f5e4d3c2b1a0" txn="'.$txn.'" ts="2026-10-22T14:30:05.123+05:30" info=""/>');

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertTrue($result->sent);
        $this->assertNull($result->errorCode);
        $this->assertMatchesRegularExpression('/^DPC\d{12}[A-Za-z0-9]{8}$/', $result->txn);

        $otp = $this->root($this->sent[0]['xml']);
        $this->assertSame('Otp', $otp->nodeName);
        $this->assertSame(self::AADHAAR, $otp->getAttribute('uid'));
        $this->assertSame('2.5', $otp->getAttribute('ver'));
        $this->assertSame('A', $otp->getAttribute('type'));
        $this->assertSame('AUA01', $otp->getAttribute('ac'));
        $this->assertSame($result->txn, $otp->getAttribute('txn'));
        // IST, NO offset — an offset gets every request refused as stale.
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $otp->getAttribute('ts'));
        $this->assertSame('00', $this->child($otp, 'Opts')->getAttribute('ch'));

        // The CSC envelope: client_ts DOES carry +05:30, req_hash verifies, token travels as a header.
        $envelope = $this->sent[0]['envelope'];
        $this->assertSame('CSC-DPC-TEST', $envelope['client_id']);
        $this->assertStringEndsWith('+05:30', (string) $envelope['client_ts']);
        $this->assertTrue((new CscEnvelopeCodec(self::TOKEN))->verify((string) $envelope['req_data'], (string) $envelope['req_hash']));
        $this->assertSame(self::TOKEN, $this->sent[0]['token']);
    }

    public function test_otp_request_error_maps_uidai_code_to_a_human_message(): void
    {
        // CSC's own published sample: res_code 10 carrying a UIDAI refusal (952, OTP flooding).
        $this->fakeCsc(fn (string $txn): string => '<?xml version="1.0"?><OtpRes txn="'.$txn.'" err="952" ret="n"/>');

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('952', $result->errorCode);
        $this->assertStringContainsString('wait', $result->message);
    }

    public function test_aadhaar_without_verified_mobile_is_explained(): void
    {
        $this->fakeCsc(fn (string $txn): string => '<OtpRes txn="'.$txn.'" err="114" ret="n"/>');

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('114', $result->errorCode);
        $this->assertStringContainsString('mobile number', $result->message);
    }

    public function test_csc_code_other_than_10_is_a_service_failure_not_a_uidai_answer(): void
    {
        Http::fake([self::URL => Http::response(['res_code' => '14', 'res_msg' => 'Missing required field in Meta tag.', 'res_data' => null])]);

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('CSC-14', $result->errorCode);
        $this->assertStringContainsString('CSC code 14', $result->message);
    }

    public function test_transport_failure_returns_not_sent_without_throwing(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('CSC-TRANSPORT', $result->errorCode);
    }

    public function test_a_response_for_another_transaction_is_refused(): void
    {
        $this->fakeCsc(fn (string $txn): string => '<OtpRes ret="y" txn="DPC000000000000SOMEONE1"/>');

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('UIDAI-PROTOCOL', $result->errorCode);
    }

    public function test_a_response_whose_hash_does_not_match_is_refused(): void
    {
        Http::fake([self::URL => fn (Request $request) => Http::response([
            'res_code' => '10',
            'res_data' => base64_encode('<OtpRes ret="y"/>'),
            'res_hash' => (new CscEnvelopeCodec(self::TOKEN))->hash(base64_encode('<OtpRes ret="n"/>')),
        ])]);

        $result = $this->provider()->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('CSC-TRANSPORT', $result->errorCode);
    }

    public function test_malformed_aadhaar_is_refused_locally(): void
    {
        Http::fake();

        $this->assertSame('INVALID_AADHAAR', $this->provider()->requestOtp('234567890123')->errorCode);
        $this->assertSame('INVALID_AADHAAR', $this->provider()->requestOtp('12345')->errorCode);

        Http::assertNothingSent();
    }

    public function test_missing_configuration_sends_nothing_and_says_unavailable(): void
    {
        Http::fake();

        $result = $this->provider(token: '')->requestOtp(self::AADHAAR);

        $this->assertFalse($result->sent);
        $this->assertSame('CONFIG', $result->errorCode);
        Http::assertNothingSent();

        $verification = $this->provider(certificate: '/nonexistent/uidai.cer')->verifyOtp(self::AADHAAR, 'DPC261022143005Ab3dE9xQ', self::OTP);
        $this->assertSame('CONFIG', $verification->errorCode);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ OTP authentication

    public function test_auth_ret_y_verifies_and_the_request_is_an_otp_only_auth(): void
    {
        $txn = 'DPC261022143005Ab3dE9xQ';
        $this->fakeCsc(fn (string $sentTxn): string => '<AuthRes ret="y" code="0a1b2c3d4e5f60718293a4b5c6d7e8f9" txn="'.$sentTxn.'" ts="2026-10-22T14:31:00.000+05:30" err="" info="04{...}"/>');

        $result = $this->provider()->verifyOtp(self::AADHAAR, $txn, self::OTP);

        $this->assertTrue($result->verified);
        $this->assertNull($result->errorCode);

        $xml = $this->sent[0]['xml'];
        $this->assertStringContainsString('standalone="yes"', $xml);

        $auth = $this->root($xml);
        $this->assertSame('Auth', $auth->nodeName);
        $this->assertSame(self::AADHAAR, $auth->getAttribute('uid'));
        $this->assertSame($txn, $auth->getAttribute('txn'), 'The Auth txn must be the OTP request txn (else UIDAI 402).');
        $this->assertSame('', $auth->getAttribute('tid'));
        $this->assertSame('Y', $auth->getAttribute('rc'));

        $uses = $this->child($auth, 'Uses');
        $this->assertSame('y', $uses->getAttribute('otp'));
        $this->assertSame('n', $uses->getAttribute('bio'));
        $this->assertFalse($uses->hasAttribute('bt'));
        $this->assertStringContainsString('otp="y"', $xml);
        $this->assertStringContainsString('bio="n"', $xml);
        $this->assertStringNotContainsString('<Kyc', $xml);

        foreach (['dpId', 'rdsId', 'rdsVer', 'dc', 'mi', 'mc'] as $attribute) {
            $this->assertSame('NA', $this->child($auth, 'Meta')->getAttribute($attribute));
        }

        // The PID block decrypts with the certificate's private key to exactly an OTP-only Pid.
        $skey = $this->child($auth, 'Skey');
        $this->assertSame(gmdate('Ymd', $this->certificateExpiry), $skey->getAttribute('ci'));
        $this->assertTrue(openssl_private_decrypt((string) base64_decode($skey->textContent, true), $sessionKey, $this->privateKey, OPENSSL_PKCS1_PADDING));

        $pid = $this->gcmDecrypt($this->child($auth, 'Data')->textContent, (string) $sessionKey);
        $pidRoot = $this->root($pid);
        $this->assertSame('Pid', $pidRoot->nodeName);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $pidRoot->getAttribute('ts'));
        $this->assertSame(self::OTP, $this->child($pidRoot, 'Pv')->getAttribute('otp'));
        $this->assertSame(1, $pidRoot->childNodes->length, 'Pv and nothing else.');

        $hmac = $this->gcmDecrypt($this->child($auth, 'Hmac')->textContent, (string) $sessionKey);
        $this->assertSame(hash('sha256', $pid, true), $hmac);
    }

    public function test_auth_ret_n_returns_the_uidai_code_and_its_message(): void
    {
        $this->fakeCsc(fn (string $txn): string => '<AuthRes ret="n" code="0a1b2c3d" txn="'.$txn.'" err="400" actn=""/>');

        $result = $this->provider()->verifyOtp(self::AADHAAR, 'DPC261022143005Ab3dE9xQ', self::OTP);

        $this->assertFalse($result->verified);
        $this->assertSame('400', $result->errorCode);
        $this->assertStringContainsString('incorrect', $result->message);
        $this->assertStringNotContainsString('fingerprint', strtolower($result->message));
    }

    public function test_only_an_explicit_yes_verifies(): void
    {
        $this->fakeCsc(fn (string $txn): string => '<AuthRes txn="'.$txn.'"/>');

        $this->assertFalse($this->provider()->verifyOtp(self::AADHAAR, 'DPC261022143005Ab3dE9xQ', self::OTP)->verified);
    }

    public function test_a_foreign_or_malformed_otp_or_txn_is_refused_locally(): void
    {
        Http::fake();
        $provider = $this->provider();

        $this->assertSame('INVALID_OTP', $provider->verifyOtp(self::AADHAAR, 'DPC261022143005Ab3dE9xQ', '12345')->errorCode);
        $this->assertSame('INVALID_TXN', $provider->verifyOtp(self::AADHAAR, 'MOCKABCDEF', self::OTP)->errorCode);
        $this->assertSame('INVALID_TXN', $provider->verifyOtp(self::AADHAAR, 'DPC<script>', self::OTP)->errorCode);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ privacy

    public function test_aadhaar_and_otp_never_reach_the_log(): void
    {
        // Every path that logs: a UIDAI refusal, a CSC refusal echoing the number, a transport failure.
        $this->fakeCsc(fn (string $txn): string => '<AuthRes ret="n" txn="'.$txn.'" err="400"/>');
        $this->provider()->requestOtp(self::AADHAAR);
        $this->provider()->verifyOtp(self::AADHAAR, 'DPC261022143005Ab3dE9xQ', self::OTP);

        $this->freshHttp();
        Http::fake([self::URL => Http::response(['res_code' => '11', 'res_msg' => 'Invalid uid '.self::AADHAAR])]);
        $this->provider()->requestOtp(self::AADHAAR);

        $this->freshHttp();
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error 7 for '.self::URL)]);
        $this->provider()->verifyOtp(self::AADHAAR, 'DPC261022143005Ab3dE9xQ', self::OTP);

        $this->provider(token: '')->requestOtp(self::AADHAAR);

        $this->assertNotEmpty($this->logged);

        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::AADHAAR, $line);
            $this->assertStringNotContainsString(self::OTP, $line);
            $this->assertStringNotContainsString(self::TOKEN, $line);
        }
    }

    // ------------------------------------------------------------------ configuration

    public function test_from_config_reads_dpc_config_and_treats_blank_as_unset(): void
    {
        config()->set('dpc.aadhaar_otp.uidai', [
            'base_url' => '',
            'endpoint' => '',
            'client_id' => 'CSC-DPC',
            'auth_token' => self::TOKEN,
            'timeout_seconds' => '',
            'retry_attempts' => '',
            'txn_prefix' => '',
            'encryption_certificate' => '',
        ]);

        $settings = UidaiSettings::fromConfig();

        $this->assertSame('https://cscauths.csccloud.in', $settings->baseUrl);
        $this->assertSame('aua/springboot/auth/req', $settings->endpoint);
        $this->assertSame(30, $settings->timeoutSeconds);
        $this->assertSame(0, $settings->retryAttempts);
        $this->assertSame('DPC', $settings->txnPrefix);
        $this->assertNull($settings->encryptionCertificatePath);
        $this->assertSame([], $settings->problems(forVerification: false));
        $this->assertCount(1, $settings->problems(forVerification: true));
        $this->assertStringNotContainsString(self::TOKEN, print_r($settings, true));

        $provider = UidaiAadhaarOtpProvider::fromConfig();
        $this->assertSame('uidai', $provider->name());
    }

    public function test_a_txn_prefix_in_the_reserved_u_namespace_is_a_configuration_problem(): void
    {
        $this->assertNotSame([], $this->settings(prefix: 'UKC')->problems(forVerification: false));
    }

    // ------------------------------------------------------------------ helpers

    private function provider(?string $token = null, ?string $certificate = null): UidaiAadhaarOtpProvider
    {
        return new UidaiAadhaarOtpProvider($this->settings($token, $certificate), app(Factory::class));
    }

    private function settings(?string $token = null, ?string $certificate = null, string $prefix = 'DPC'): UidaiSettings
    {
        return new UidaiSettings(
            baseUrl: 'https://csc.test',
            endpoint: 'aua/springboot/auth/req',
            clientId: 'CSC-DPC-TEST',
            authToken: $token ?? self::TOKEN,
            auaCode: 'AUA01',
            subAuaCode: 'SUBAUA01',
            licenceKey: 'LK-TEST',
            encryptionCertificatePath: $certificate ?? $this->certificatePath,
            txnPrefix: $prefix,
        );
    }

    /**
     * A faked CSC endpoint: records what was sent and answers with the given
     * UIDAI XML wrapped in a real envelope.
     *
     * @param  callable(string): string  $answer  given the txn that was sent
     */
    private function fakeCsc(callable $answer): void
    {
        $codec = new CscEnvelopeCodec(self::TOKEN);

        Http::fake([self::URL => function (Request $request) use ($answer, $codec) {
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($request->body(), true);
            $xml = (string) base64_decode((string) $envelope['req_data'], true);

            $this->sent[] = ['xml' => $xml, 'envelope' => $envelope, 'token' => $request->header('authToken')[0] ?? ''];

            $resData = base64_encode($answer($this->root($xml)->getAttribute('txn')));

            return Http::response([
                'res_code' => '10',
                'client_id' => $envelope['client_id'],
                'res_msg' => 'Success',
                'res_data' => $resData,
                'res_hash' => $codec->hash($resData),
                'status' => null,
            ]);
        }]);
    }

    /** Http::fake() stubs accumulate; a new factory lets one test change the answer. */
    private function freshHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function root(string $xml): DOMElement
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $this->assertInstanceOf(DOMElement::class, $document->documentElement);

        return $document->documentElement;
    }

    private function child(DOMElement $parent, string $name): DOMElement
    {
        $node = $parent->getElementsByTagName($name)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "<{$name}> missing");

        return $node;
    }

    /** Authentication API 2.5 §4.1, inverted: ts(19) || ciphertext || tag(16). */
    private function gcmDecrypt(string $base64, string $sessionKey): string
    {
        $raw = (string) base64_decode($base64, true);
        $ts = substr($raw, 0, 19);
        $plaintext = openssl_decrypt(
            substr($raw, 19, -16),
            'aes-256-gcm',
            $sessionKey,
            OPENSSL_RAW_DATA,
            substr($ts, -12),
            substr($raw, -16),
            substr($ts, -16),
        );

        $this->assertIsString($plaintext, 'The PID block did not decrypt.');

        return $plaintext;
    }

    /** @return array{0: string, 1: \OpenSSLAsymmetricKey, 2: int} */
    private function throwawayCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'UIDAI test encryption'], $key);
        $this->assertNotFalse($csr);
        $this->assertNotTrue($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365);
        $this->assertNotFalse($certificate);

        openssl_x509_export($certificate, $pem);
        $path = (string) tempnam(sys_get_temp_dir(), 'uidai-test-cert-');
        file_put_contents($path, $pem);

        $parsed = openssl_x509_parse($certificate);
        $this->assertIsArray($parsed);

        return [$path, $key, (int) $parsed['validTo_time_t']];
    }
}
