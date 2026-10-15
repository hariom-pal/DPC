<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Committee\ValueObjects\CommitteeRules;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Enums\MasterStatus;

/**
 * The rules a DPC committee must satisfy, resolved once.
 *
 * **Two sources, and the order between them is the point.** The Committee
 * Configuration master is the Administrator's, and where it states a bound that
 * bound wins; `config('dpc.committee')` supplies the rest and the defaults. So a
 * Commission that has configured its committee type gets its own numbers, and one
 * that has not still gets the register's 3-to-6 rather than nothing.
 *
 * Resolved in one place because three callers ask: the compliance check that
 * refuses an invalid committee, the screen that explains the requirement, and the
 * request that validates the posted list. Three copies of "minimum three" is how
 * one of them comes to say two.
 *
 * **The Chairman requirement is no longer one of the two sources** (change register
 * of 2026-08-26, item 9): *"Every DPC must always contain the CGPSC Chairman.
 * Chairman is mandatory."* It is therefore not read from configuration at all — see
 * {@see forProposal()}. The member-level rule of item 16 was removed on the same
 * date; {@see CommitteeRules} records why.
 */
class CommitteeRuleResolver
{
    /**
     * The committee type a DPC committee is.
     *
     * A constant rather than a literal in two places, because
     * `SaveDpcCommitteeRequest` uses the same key and the two silently agreeing is
     * not the same as the two being the same string.
     */
    public const COMMITTEE_TYPE = 'dpc_committee';

    public function forProposal(DpcProposal $proposal): CommitteeRules
    {
        $configuration = $this->configurationFor($proposal);

        /*
         | Item 9 (2026-08-26): the CGPSC Chairman sits on every DPC, always.
         |
         | Not `config('dpc.committee.chairman_required')` and not the committee
         | type's `chairman_mandatory`, both of which this used to read. The
         | register states the requirement without qualification, and a setting that
         | could switch it off would let an installation configure away a rule the
         | Commission stated — quietly, and only discoverable by composing a
         | committee and noticing the seat was absent.
         |
         | The configuration column is left in place and simply not consulted here;
         | other committee types may still use it.
         */
        $chairmanRequired = true;

        return new CommitteeRules(
            /*
             | Both bounds mean **members besides the Chairman**, which is the
             | register's own framing: "Minimum Members 3, Maximum Members 6,
             | Chairman Additional".
             |
             | A registered Committee Configuration overrides them, and its numbers
             | are converted rather than used raw: `committee_configurations`
             | `min_members`/`max_members` have always counted the *whole*
             | committee — that is how `SaveDpcCommitteeRequest` read them before
             | this — so a mandatory Chairman is subtracted from each. Without the
             | conversion the same field would mean two different things depending
             | on where the number came from, which is precisely how a committee
             | comes to be refused for having the size the screen asked for.
             |
             | An Administrator's configured numbers win over the register's
             | defaults, because an Administrator who set them meant them.
             */
            minMembers: $this->besidesChairman(
                (int) ($configuration === null ? config('dpc.committee.min_members', 3) : $configuration->min_members),
                $configuration !== null,
            ),
            maxMembers: $this->besidesChairman(
                (int) ($configuration === null ? config('dpc.committee.max_members', 6) : $configuration->max_members),
                $configuration !== null,
            ),

            chairmanRequired: $chairmanRequired,

            requiredCategories: $this->requiredCategories(),

            /*
             | Business clarification of 2026-09-06: at least one member belongs to
             | a reserved social category — any one of SC, ST or OBC. Carried
             | separately from `requiredCategories` because it is an any-of rather
             | than an each-of; see `config/dpc.php` for why the two cannot share a
             | list.
             */
            requiresReservedCategory: (bool) config('dpc.committee.require_reserved_category', false),

            configuration: $configuration,
        );
    }

    /**
     * Convert a whole-committee bound into a besides-the-Chairman one.
     *
     * Floored at zero: a configuration stating a whole committee of exactly one
     * with a mandatory Chairman means a Chairman and nobody else, not minus-one
     * members.
     */
    private function besidesChairman(int $bound, bool $convert): int
    {
        return $convert ? max(0, $bound - 1) : $bound;
    }

    /**
     * The categories a committee must represent.
     *
     * Configuration rather than master data, because the register does not say
     * which categories are mandatory — it says a member may satisfy several and
     * that the selection should be optimised. So the *set* is a setting, and the
     * default is empty: a system that invented "every committee needs an SC
     * member" would be inventing a business rule, and an empty requirement means
     * the optimiser only has the size bounds to satisfy, which is exactly the
     * pre-existing behaviour.
     *
     * Recorded as a Pending Business Question in the final report.
     *
     * @return list<MemberCategory>
     */
    private function requiredCategories(): array
    {
        $configured = (array) config('dpc.committee.required_categories', []);

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?MemberCategory => is_string($value) ? MemberCategory::tryFrom($value) : null,
            $configured,
        )));
    }

    /**
     * The **DPC committee** type's live configuration, if the Administrator has
     * registered one.
     *
     * Keyed on the committee type `dpc_committee` — the same key
     * `SaveDpcCommitteeRequest` has always used and the same one
     * `CommitteeMasterSeeder` creates. It is deliberately *not* keyed on the DPC
     * type: "Fresh" and "Review" are kinds of promotion exercise, not kinds of
     * committee, and reading the DPC type here would silently find no
     * configuration at all and fall back to the defaults — which is exactly the
     * bug this note exists to stop being reintroduced.
     *
     * Effective-dated, so a configuration that has lapsed does not govern a
     * proposal raised after it.
     */
    private function configurationFor(DpcProposal $proposal): ?CommitteeConfiguration
    {
        return CommitteeConfiguration::query()
            ->where('status', MasterStatus::Active->value)
            ->whereHas('committeeType', fn ($query) => $query->where('code', self::COMMITTEE_TYPE))
            ->where(fn ($query) => $query
                ->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $proposal->proposal_date))
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $proposal->proposal_date))
            ->orderByDesc('effective_from')
            ->first();
    }
}
