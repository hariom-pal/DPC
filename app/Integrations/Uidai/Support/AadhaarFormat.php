<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Support;

use SensitiveParameter;

/**
 * Whether a string can be an Aadhaar number at all: twelve digits, not starting
 * with 0 or 1 (UIDAI never issues those), with a valid Verhoeff check digit.
 *
 * Checked locally because the alternative is spending a UIDAI transaction — and
 * possibly an SMS to a stranger's mobile — to be told the same thing. Ported in
 * miniature from the CGMFPFED Welfare Platform's `AadhaarNumber` + `Verhoeff`;
 * nothing here stores, masks or prints the number.
 */
final class AadhaarFormat
{
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /** Spaces and hyphens removed — the one definition of "the same number". */
    public static function normalise(#[SensitiveParameter] string $value): string
    {
        return preg_replace('/[\s\-]/', '', $value) ?? '';
    }

    public static function isValid(#[SensitiveParameter] string $digits): bool
    {
        if (preg_match('/^[2-9]\d{11}$/', $digits) !== 1) {
            return false;
        }

        $check = 0;

        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $check = self::D[$check][self::P[$position % 8][(int) $digit]];
        }

        return $check === 0;
    }
}
