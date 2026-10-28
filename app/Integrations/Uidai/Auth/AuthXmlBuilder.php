<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Auth;

use App\Integrations\Uidai\Otp\PidBlock;
use App\Integrations\Uidai\Otp\TransactionId;
use App\Integrations\Uidai\UidaiProtocolException;
use App\Integrations\Uidai\UidaiSettings;
use DOMDocument;
use DOMElement;
use SensitiveParameter;

/**
 * The OTP-only `<Auth>` document — Authentication API 2.5 §3.3. Ported from the
 * CGMFPFED Welfare Platform (`AuthXmlBuilder`) with the biometric branch removed.
 *
 * ```xml
 * <Auth lk="" ac="" sa="" uid="" ver="2.5" tid="" txn="" rc="Y">
 *   <Uses pi="n" pa="n" pfa="n" bio="n" pin="n" otp="y"/>
 *   <Meta dpId="NA" rdsId="NA" rdsVer="NA" dc="NA" mi="NA" mc="NA"/>
 *   <Skey ci="YYYYMMDD">…</Skey>
 *   <Hmac>…</Hmac>
 *   <Data type="X">…</Data>
 * </Auth>
 * ```
 *
 * Plain `<Auth>`, not `<Kyc>`: DPC needs UIDAI's yes or no and nothing else.
 * CSC's Java reference posts a bare `<Auth>` on this path, base64'd once.
 *
 * **The OTP factor is fixed, and that is the point.** Welfare's OTP verification
 * failed with K-100 on every attempt for its whole life because the request
 * defaulted to the fingerprint factor: `bio="y" bt="FMR" otp="n"` and
 * `tid="registered"` over a PID block holding an OTP. UIDAI was asked to match
 * a fingerprint that was not there. Here there is no other factor to default to.
 *
 * **`tid` is empty and `Meta` is "NA".** §3.3.1: `tid` "must be ''" when not
 * using biometrics, and the Meta fields are documented as "NA" when registered
 * devices are not used. Naming a device that never took part is both a lie and
 * a rejection (520-527).
 *
 * **No `<Signature>`.** CSC's worked sample carries none: CSC, as the ASA, signs
 * on our behalf — as §3.3.1 permits. If CSC says otherwise, signing goes here.
 *
 * Attribute order and the `standalone="yes"` declaration are the working
 * standalone implementation's, which completes live transactions.
 */
final readonly class AuthXmlBuilder
{
    /** §3.3.1: the only permitted consent value; anything else is error 512. */
    private const CONSENT = 'Y';

    private const NOT_APPLICABLE = 'NA';

    public function __construct(
        private UidaiSettings $settings,
    ) {}

    /** @throws UidaiProtocolException */
    public function build(#[SensitiveParameter] string $aadhaar, string $txn, PidBlock $pid): string
    {
        TransactionId::assertAcceptable($txn);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->xmlStandalone = true;

        $auth = $document->createElement('Auth');
        $auth->setAttribute('lk', $this->settings->licenceKey);
        $auth->setAttribute('ac', $this->settings->auaCode);
        $auth->setAttribute('sa', $this->settings->subAuaCode);
        $auth->setAttribute('uid', $aadhaar);
        $auth->setAttribute('ver', $this->settings->apiVersion);
        $auth->setAttribute('tid', '');
        $auth->setAttribute('txn', $txn);
        $auth->setAttribute('rc', self::CONSENT);

        $auth->appendChild($this->uses($document));
        $auth->appendChild($this->meta($document));

        $skey = $document->createElement('Skey', $pid->sessionKey());
        $skey->setAttribute('ci', $pid->certificateIdentifier());
        $auth->appendChild($skey);

        $auth->appendChild($document->createElement('Hmac', $pid->hmac()));

        $data = $document->createElement('Data', $pid->data());
        $data->setAttribute('type', 'X');
        $auth->appendChild($data);

        $document->appendChild($auth);

        return (string) $document->saveXML();
    }

    /**
     * What the PID block contains: an OTP and nothing else. No `bt` — it names a
     * biometric type and is error 820/821 without one.
     */
    private function uses(DOMDocument $document): DOMElement
    {
        $uses = $document->createElement('Uses');

        foreach (['pi' => 'n', 'pa' => 'n', 'pfa' => 'n', 'bio' => 'n', 'pin' => 'n', 'otp' => 'y'] as $name => $value) {
            $uses->setAttribute($name, $value);
        }

        return $uses;
    }

    /** `<Meta>` — not `<Device>`: Welfare's live call answered res_code 14 until it was renamed. */
    private function meta(DOMDocument $document): DOMElement
    {
        $meta = $document->createElement('Meta');

        foreach (['dpId', 'rdsId', 'rdsVer', 'dc', 'mi', 'mc'] as $name) {
            $meta->setAttribute($name, self::NOT_APPLICABLE);
        }

        return $meta;
    }
}
