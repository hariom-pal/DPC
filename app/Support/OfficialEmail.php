<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which addresses count as official e-mail: anything under `gov.in` or `nic.in`
 * (`nic.in` allowed at UAT, 2026-11-03 — many officers' mailboxes are NIC's).
 *
 * Anchored on `@…gov.in` / `@…nic.in` rather than `ends_with`, which would also
 * accept `someone@notgov.in`. The one place the rule lives; the committee form's
 * script (`public/js/forms/dpc-committee.js`) repeats the pattern for the browser.
 */
final class OfficialEmail
{
    public const PATTERN = '/@(?:[A-Za-z0-9-]+\.)*(?:gov|nic)\.in$/i';

    public const RULE = 'regex:'.self::PATTERN;

    public static function matches(string $email): bool
    {
        return preg_match(self::PATTERN, $email) === 1;
    }
}
