<?php

declare(strict_types=1);

namespace App\Domain\Master\Data;

use Database\Seeders\QualificationSeeder;

/**
 * The canonical starting list for the Qualification master.
 *
 * It lives in one place because two things need it and they must not drift:
 * the migration that introduces `recruitment_rule_sources.qualification_id`
 * seeds it so that its backfill has something to match against on a
 * migrate-only deployment, and {@see QualificationSeeder}
 * upserts it by code so re-seeding is harmless.
 *
 * It is a *starting* list, not a closed one. The master is CRUD-managed by the
 * CGPSC Admin from Administration → Qualifications, and the entries below carry
 * no special status once created.
 *
 * Ordered from school-leaving upwards, which is the order a user scanning the
 * dropdown expects.
 *
 * Some entries are not textbook degrees — `Graduate`, `Post-Graduate` and
 * `Post-Graduate with B.Ed.` are the broad requirements the seeded Schedule-IV
 * rules actually cite. They are included because the migration's backfill maps
 * existing free text onto this list, and omitting them would leave live rows
 * unmapped.
 *
 * Names keep the punctuation already stored in live data (`B.E.`, `LL.B.`,
 * `M.B.B.S.`, `Post-Graduate`). {@see matchKey()} folds punctuation away, so
 * `BE`, `B.E.` and `B E` all resolve to the same row and the display form can
 * stay consistent without breaking a lookup.
 */
final class QualificationCatalogue
{
    public const NOT_APPLICABLE = 'NA';

    /**
     * @var list<array{code:string, name_en:string, name_hi:string, description:string}>
     */
    public const LIST = [
        [
            'code' => 'CLASS10',
            'name_en' => '10th',
            'name_hi' => 'दसवीं',
            'description' => 'High School Certificate — Class 10 from a recognised board.',
        ],
        [
            'code' => 'CLASS12',
            'name_en' => '12th',
            'name_hi' => 'बारहवीं',
            'description' => 'Higher Secondary Certificate — Class 12 from a recognised board.',
        ],
        [
            'code' => 'ITI',
            'name_en' => 'ITI',
            'name_hi' => 'आई.टी.आई.',
            'description' => 'Industrial Training Institute certificate in the relevant trade.',
        ],
        [
            'code' => 'DIPLOMA',
            'name_en' => 'Diploma',
            'name_hi' => 'डिप्लोमा',
            'description' => 'A three-year diploma in the relevant discipline from a recognised institution.',
        ],
        [
            'code' => 'GRAD',
            'name_en' => 'Graduate',
            'name_hi' => 'स्नातक',
            'description' => 'A bachelor’s degree in any discipline from a recognised university.',
        ],
        [
            'code' => 'PG',
            'name_en' => 'Post-Graduate',
            'name_hi' => 'स्नातकोत्तर',
            'description' => 'A master’s degree in any discipline from a recognised university.',
        ],
        [
            'code' => 'PG_BED',
            'name_en' => 'Post-Graduate with B.Ed.',
            'name_hi' => 'बी.एड. सहित स्नातकोत्तर',
            'description' => 'A master’s degree together with a Bachelor of Education — the standard requirement for teaching posts above Middle School.',
        ],
        [
            'code' => 'BED',
            'name_en' => 'B.Ed.',
            'name_hi' => 'बी.एड.',
            'description' => 'Bachelor of Education.',
        ],
        [
            'code' => 'MED',
            'name_en' => 'M.Ed.',
            'name_hi' => 'एम.एड.',
            'description' => 'Master of Education.',
        ],
        [
            'code' => 'BE',
            'name_en' => 'B.E.',
            'name_hi' => 'बी.ई.',
            'description' => 'Bachelor of Engineering.',
        ],
        [
            'code' => 'BTECH',
            'name_en' => 'B.Tech.',
            'name_hi' => 'बी.टेक.',
            'description' => 'Bachelor of Technology.',
        ],
        [
            'code' => 'LLB',
            'name_en' => 'LL.B.',
            'name_hi' => 'एल.एल.बी.',
            'description' => 'Bachelor of Laws.',
        ],
        [
            'code' => 'MBBS',
            'name_en' => 'M.B.B.S.',
            'name_hi' => 'एम.बी.बी.एस.',
            'description' => 'Bachelor of Medicine and Bachelor of Surgery.',
        ],
        [
            'code' => 'MSC',
            'name_en' => 'M.Sc.',
            'name_hi' => 'एम.एस-सी.',
            'description' => 'Master of Science.',
        ],
        [
            'code' => 'MCOM',
            'name_en' => 'M.Com.',
            'name_hi' => 'एम.कॉम.',
            'description' => 'Master of Commerce.',
        ],
        [
            'code' => 'MA',
            'name_en' => 'M.A.',
            'name_hi' => 'एम.ए.',
            'description' => 'Master of Arts.',
        ],
        [
            'code' => 'PHD',
            'name_en' => 'Ph.D.',
            'name_hi' => 'पी-एच.डी.',
            'description' => 'Doctor of Philosophy.',
        ],
        // Last in every dropdown (MoM of 2026-10-29): the rule names no qualification.
        [
            'code' => self::NOT_APPLICABLE,
            'name_en' => 'NA',
            'name_hi' => 'लागू नहीं',
            'description' => 'Not applicable — the recruitment rule names no qualification for this feeder.',
        ],
    ];

    /**
     * The match key used to reconcile hand-entered free text with a master row:
     * case-folded with punctuation and whitespace removed, so "Post-Graduate",
     * "post graduate" and "Post Graduate." all reduce to the same thing.
     */
    public static function matchKey(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
    }
}
