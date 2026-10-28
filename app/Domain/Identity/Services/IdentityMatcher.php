<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Authentication\Models\User;
use App\Integrations\DigiLocker\DigiLockerIdentity;

/**
 * Compare an officer's recorded identity with what DigiLocker returned
 * (2026-10-27). Pure and strict:
 *
 * - date of birth and gender must be exactly the same;
 * - the name is compared after ignoring letter case, extra spaces, full stops and
 *   common salutations (Shri, Smt., Dr., श्री …) — nothing else. Initials against a
 *   full name, a spelling difference, or Hindi against English is a mismatch, for a
 *   person to correct or review: an uncertain match is never approved here.
 */
final class IdentityMatcher
{
    /**
     * @var list<string>
     */
    private const SALUTATIONS = [
        'shri', 'shree', 'sri', 'smt', 'shrimati', 'srimati', 'kumari', 'kum', 'km', 'sushri', 'dr', 'mr', 'mrs', 'ms', 'miss',
        'श्री', 'श्रीमती', 'सुश्री', 'कुमारी', 'डॉ', 'डा',
    ];

    /**
     * The fields that differ: `name`, `date_of_birth`, `gender`. Empty = a match.
     *
     * @return list<string>
     */
    public function mismatches(User $user, DigiLockerIdentity $identity): array
    {
        $fields = [];

        if (self::normaliseName((string) $user->name) !== self::normaliseName($identity->name)) {
            $fields[] = 'name';
        }

        if ($user->date_of_birth === null || $user->date_of_birth->toDateString() !== $identity->dateOfBirth->toDateString()) {
            $fields[] = 'date_of_birth';
        }

        if ($user->gender !== $identity->gender) {
            $fields[] = 'gender';
        }

        return $fields;
    }

    public static function normaliseName(string $name): string
    {
        $name = mb_strtolower(str_replace('.', ' ', $name));
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        while ($words !== [] && in_array($words[0], self::SALUTATIONS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }
}
