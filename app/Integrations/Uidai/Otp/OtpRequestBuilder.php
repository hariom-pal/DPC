<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Otp;

use App\Integrations\Uidai\UidaiProtocolException;
use App\Integrations\Uidai\UidaiSettings;
use DOMDocument;
use SensitiveParameter;

/**
 * The `<Otp>` document — UIDAI OTP Request API 2.5 §3.3. Ported from the
 * CGMFPFED Welfare Platform (`OtpRequestBuilder`).
 *
 *     <Otp uid="" ac="" sa="" ver="2.5" txn="" ts="" lk="" type="A">
 *       <Opts ch="00"/>
 *     </Otp>
 *
 * **This asks UIDAI to send a code. It proves nothing.** The resident is
 * verified only when the code comes back inside an `<Auth>`.
 *
 * **The timestamp is a deadline.** §3.3.1: a request received more than 20
 * minutes after `ts` is rejected, and the zone "should not be specified and is
 * automatically defaulted to IST" — so `ts` is IST with NO offset; a server in
 * UTC sending its own offset would have every request refused as stale.
 */
final readonly class OtpRequestBuilder
{
    /** §3.3.1, `type`: the `uid` attribute carries an Aadhaar number. */
    private const TYPE_AADHAAR = 'A';

    /** §3.3.1, `ch`: "00" = SMS and e-mail, UIDAI's default — the value in CSC's own sample. */
    private const CHANNEL_SMS_AND_EMAIL = '00';

    public function __construct(
        private UidaiSettings $settings,
    ) {}

    /**
     * @param  string  $txn  links the request to its response and to the `<Auth>` that follows
     * @param  string  $timestamp  IST, `Y-m-d\TH:i:s`, no offset
     *
     * @throws UidaiProtocolException
     */
    public function build(#[SensitiveParameter] string $aadhaar, string $txn, string $timestamp): string
    {
        TransactionId::assertAcceptable($txn);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $otp = $document->createElement('Otp');
        $otp->setAttribute('uid', $aadhaar);
        $otp->setAttribute('ac', $this->settings->auaCode);
        $otp->setAttribute('sa', $this->settings->subAuaCode);
        $otp->setAttribute('ver', $this->settings->apiVersion);
        $otp->setAttribute('txn', $txn);
        $otp->setAttribute('ts', $timestamp);
        $otp->setAttribute('lk', $this->settings->licenceKey);
        $otp->setAttribute('type', self::TYPE_AADHAAR);

        $opts = $document->createElement('Opts');
        $opts->setAttribute('ch', self::CHANNEL_SMS_AND_EMAIL);
        $otp->appendChild($opts);

        $document->appendChild($otp);

        return (string) $document->saveXML();
    }
}
