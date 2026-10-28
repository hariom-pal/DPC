<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Auth;

/**
 * UIDAI error codes that can arise on the OTP path — OTP Request API 2.5 §2.5
 * and Authentication API 2.5 §3.4.1 — each with a sentence an officer can act on.
 *
 * Ported from the CGMFPFED Welfare Platform's `UidaiErrorCatalogue`, keeping
 * only the codes an OTP request or an OTP-only `<Auth>` can produce, and using
 * that catalogue's OTP wording for the factor-neutral codes. Welfare learned the
 * hard way that a biometric sentence on an OTP failure ("the fingerprint did not
 * match") sends an investigation the wrong way for a week; nothing here mentions
 * a fingerprint.
 *
 * Three kinds of sentence, by who can act:
 *  - the officer (wrong or expired code, Aadhaar has no verified mobile, locked);
 *  - nobody right now (UIDAI-side trouble — try again shortly);
 *  - us (our request or our agreement with CSC/UIDAI is wrong) — the officer is
 *    told the service is unavailable, with the code so support can trace it.
 *
 * An unknown code degrades to a "try again; if it repeats, report code X"
 * sentence rather than throwing: UIDAI publishes codes without notice.
 */
final class UidaiErrorCatalogue
{
    private const OURS = 'Aadhaar verification is unavailable because of a problem at our end (UIDAI error %s). Please try again later or contact the helpdesk.';

    private const TRANSIENT = 'UIDAI is having a temporary technical problem. Please wait a moment and try again.';

    /**
     * Keyed by code. PHP turns numeric-string keys into ints, hence int|string.
     *
     * @var array<int|string, string>
     */
    private const MESSAGES = [
        /* ---- OTP request: the officer's Aadhaar record ---- */
        '110' => 'This Aadhaar number has no verified mobile number or e-mail with UIDAI, so no OTP can be sent. Update it at an Aadhaar centre.',
        '111' => 'This Aadhaar number has no verified mobile number with UIDAI, so no OTP can be sent. Update it at an Aadhaar centre.',
        '112' => 'This Aadhaar number has neither a mobile number nor an e-mail with UIDAI, so no OTP can be sent. Update it at an Aadhaar centre.',
        '113' => 'The e-mail on this Aadhaar is not verified with UIDAI, so no OTP can be sent to it. Verify it with UIDAI.',
        '114' => 'The mobile number on this Aadhaar is not verified with UIDAI, so no OTP can be sent. Verify it with UIDAI.',
        '115' => 'Neither the mobile number nor the e-mail on this Aadhaar is verified with UIDAI, so no OTP can be sent.',
        '521' => 'The mobile number supplied is not valid.',
        '523' => 'UIDAI refused the request as stale (more than 20 minutes old). Please try again; if it repeats, report error 523.',
        '952' => 'Too many OTP requests for this Aadhaar in a short time. Please wait a few minutes before requesting another OTP.',

        /* ---- OTP authentication: the code ---- */
        '400' => 'The Aadhaar OTP is incorrect or has expired. Check the code, or request a new OTP.',
        '402' => 'This OTP belongs to a different request. Request a new OTP and use the latest code.',
        '403' => 'Too many incorrect attempts, or no OTP was generated. Request a new OTP.',
        '563' => 'UIDAI received this exact request twice. Request a new OTP rather than resubmitting.',
        'K-100' => 'The Aadhaar OTP was not accepted by UIDAI. It may have been mistyped or have expired. Request a new OTP and try again.',

        /* ---- The Aadhaar number itself ---- */
        '331' => 'This Aadhaar number is locked by its holder. Unlock it at uidai.gov.in or in the mAadhaar app, then try again.',
        '332' => 'Authentication by Aadhaar number has been blocked by its holder. Unblock it with UIDAI, then try again.',
        '995' => 'This Aadhaar number has been suspended by the competent authority.',
        '996' => 'This Aadhaar number has been cancelled and can no longer be authenticated.',
        '997' => 'This Aadhaar number is suspended and cannot currently be authenticated.',
        '998' => 'UIDAI does not recognise this Aadhaar number. Check it against the Aadhaar card.',

        /* ---- UIDAI-side, transient ---- */
        '930' => self::TRANSIENT, '931' => self::TRANSIENT, '932' => self::TRANSIENT, '933' => self::TRANSIENT,
        '934' => self::TRANSIENT, '935' => self::TRANSIENT, '936' => self::TRANSIENT, '937' => self::TRANSIENT,
        '938' => self::TRANSIENT, '939' => self::TRANSIENT,
        '950' => 'UIDAI could not send the OTP because of a technical problem. Please try again shortly.',
        '999' => self::TRANSIENT,
        'K-955' => self::TRANSIENT,
        'K-999' => self::TRANSIENT,
    ];

    /**
     * Our request or our agreement is wrong. The officer cannot act on any of
     * these; the sentence says so and carries the code for the helpdesk.
     *
     * 500-504 encryption/certificate, 510-512 malformed/consent, 515/517 VID,
     * 520 terminal type, 530/531 AUA/Sub-AUA codes, 540-543 version/linkage,
     * 550 Uses mismatch, 561/562 clock (OUR server's, on this path), 564-570
     * integrity/licence/characters/signature, 579 OTP not licensed, 587 txn
     * namespace, 740 OTP declared but not sent, 901, 940/941 ASA channel, 980.
     *
     * @var list<string>
     */
    private const OUR_FAULT = [
        '500', '501', '502', '503', '504', '505', '510', '511', '512', '515', '517', '520',
        '530', '531', '540', '541', '542', '543', '550', '552', '561', '562', '564', '565',
        '566', '567', '569', '570', '579', '587', '740', '901', '940', '941', '980',
        'K-540', 'K-541', 'K-542', 'K-544', 'K-550', 'K-551', 'K-552', 'K-569', 'K-570',
    ];

    /** The sentence for a UIDAI error code. Never empty. */
    public function messageFor(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Aadhaar authentication was not successful. Please try again.';
        }

        if (in_array($code, self::OUR_FAULT, true)) {
            return sprintf(self::OURS, $code);
        }

        return self::MESSAGES[$code]
            ?? 'UIDAI returned an error we do not recognise (code '.$code.'). Please try again; if it repeats, report the code to the helpdesk.';
    }

    /** Whether the code means our configuration or request is wrong — logged at error, not warning. */
    public function isOurFault(?string $code): bool
    {
        return $code !== null && in_array($code, self::OUR_FAULT, true);
    }
}
