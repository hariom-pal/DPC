<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcProposal;

/**
 * Whether a committee as composed satisfies the rules in force (change register of
 * 2026-08-26, items 8 and 9).
 *
 * ### The rule item 8 states, and the rule it forbids
 *
 * > *The committee validation engine must validate the **combined** attributes of
 * > all selected members. It should always try to satisfy all mandatory committee
 * > requirements with the **minimum possible number of members**. Do NOT require
 * > separate members for every attribute.*
 *
 * The register's own worked example is the specification:
 *
 * | Member | Represents |
 * |---|---|
 * | 1 | Female, ST, Bhar Sadhak Sachiv |
 * | 2 | SC |
 * | 3 | OBC |
 *
 * Five requirements, three members, **valid**. So coverage is asked of the
 * committee as a whole and never of a member individually: the question is *"is
 * there at least one member representing ST"*, not *"is there an ST member whose
 * only job is ST"*. Counting attributes rather than covering them is the specific
 * mistake this class is written to not make — it would refuse the register's own
 * example.
 *
 * ### The one rule that *is* asked of a member individually
 *
 * **Business clarification of 2026-09-05.** SC, ST and OBC are mutually exclusive:
 * a person belongs to at most one reserved social category, and a member claiming
 * two is refused by {@see socialCategoryFindings()}. This does not contradict the
 * paragraph above — a committee may contain an SC member *and* an ST member, and
 * routinely must. What it may not contain is one person who is both.
 *
 * Female, Bhar Sadhak Sachiv and Vibhag Sachiv are unaffected and still combine
 * freely, with each other and with whichever social category was chosen. A member
 * claiming no social category is UR (General), which is a default rather than a
 * fault and is therefore never a finding.
 *
 * ### Reads the posted rows, not the database
 *
 * Attributes moved onto `dpc_committee_members` when the Commission Members master
 * was withdrawn, so there is no longer a master to look a member's standing up in.
 * That is a simplification: the committee is now entirely described by what was
 * submitted, and this check runs on the submission **before** the write, so an
 * invalid committee is refused rather than saved and then reported.
 *
 * ### Returns findings rather than throwing
 *
 * A Form Request wants to show every problem at once. A service that threw on the
 * first would make an officer fix three faults in three round trips.
 */
class CommitteeComplianceService
{
    public function __construct(private readonly CommitteeRuleResolver $rules) {}

    /**
     * Everything wrong with this committee, in the order an officer would fix it.
     *
     * **This is the whole list, and most of it does not refuse a save.** See
     * {@see blockingFindings()} for the split and why it exists: a committee is
     * *saved* as a draft in whatever state the officer has it in, and is *submitted*
     * only when this list is empty.
     *
     * `$members` is the posted list — each row carrying its capacity and its six
     * attribute flags — so this can run before the write, and can equally be run
     * over the rows already saved.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return list<string>
     */
    public function findings(DpcProposal $proposal, array $members): array
    {
        $rules = $this->rules->forProposal($proposal);
        $findings = [];

        $chairmanSeats = $this->withCapacity($members, CommitteeCapacity::CgpscChairman);
        $ordinary = $this->withCapacity($members, CommitteeCapacity::Member);

        /*
         | Item 15 of the 2026-08-24 register, unchanged: the bounds count the
         | members the Chairman sits *with*. "Minimum 3, Maximum 6, Chairman
         | Additional" — so a Chairman plus three members is four people and is
         | valid, and counting the Chairman toward the three would make it invalid.
         */
        if (count($ordinary) < $rules->minMembers) {
            $findings[] = __('dpc.committee.findings.too_few', [
                'min' => $rules->minMembers,
                'count' => count($ordinary),
            ]);
        }

        if (count($ordinary) > $rules->maxMembers) {
            $findings[] = __('dpc.committee.findings.too_many', [
                'max' => $rules->maxMembers,
                'count' => count($ordinary),
            ]);
        }

        /*
         | Item 9. The CGPSC Chairman's seat is placed by the system and is not the
         | Authorized Officer's to create or remove, so a submission missing it — or
         | carrying two — means the posted list was tampered with rather than that
         | the officer made a composition mistake. Refused all the same, and said
         | plainly.
         */
        if ($chairmanSeats === []) {
            $findings[] = __('dpc.committee.findings.chairman_missing');
        }

        if (count($chairmanSeats) > 1) {
            $findings[] = __('dpc.committee.findings.chairman_duplicated');
        }

        $findings = array_merge($findings, $this->dpcChairmanFindings($members));

        // Business clarification of 2026-09-05: one social category per member.
        $findings = array_merge($findings, $this->socialCategoryFindings($members));

        /*
         | Business clarification of 2026-09-06: at least one member belongs to a
         | reserved social category — **any one** of SC, ST or OBC.
         |
         | Asked before the each-of list below because it is the requirement an
         | officer is most likely to have missed, and the findings are returned in
         | the order somebody would fix them.
         */
        if ($rules->requiresReservedCategory && ! $this->representsAReservedCategory($members)) {
            $findings[] = __('dpc.committee.findings.reserved_category_uncovered', [
                'categories' => $rules->reservedCategoryLabel(),
            ]);
        }

        // Item 8, the whole point: coverage by the committee, not by the member.
        foreach ($this->uncovered($members, $rules->requiredCategories) as $category) {
            $findings[] = __('dpc.committee.findings.attribute_uncovered', [
                'attribute' => $category->label(),
            ]);
        }

        return $findings;
    }

    /**
     * The subset that **refuses the save**, as opposed to marking the committee
     * incomplete.
     *
     * ### Why there are two lists
     *
     * Business, 2026-09-06:
     *
     * > *I removed a committee member — why does it come back after a refresh?
     * > Remove means remove. And on save, save it as a draft but keep showing it as
     * > incomplete in the workflow section.*
     *
     * Until then every finding refused the save, which had a consequence nobody
     * chose: **a committee could not be edited towards compliance one member at a
     * time.** Removing one of three left two, two is below the minimum, the save was
     * refused, nothing was written — and the removal appeared to undo itself on the
     * next page load. The officer had to arrive at a fully compliant committee in a
     * single submission or make no progress at all.
     *
     * So the composition requirements moved to where they were always headed:
     * `DpcProposalCompletenessService` reports the committee section incomplete
     * while any finding stands, and an incomplete section blocks **submission**.
     * The rule is enforced exactly as hard as before — a non-compliant committee
     * still cannot reach the Administrative Head — but it is enforced at the gate
     * rather than at every keystroke.
     *
     * ### What still refuses a save
     *
     * The faults that are not *"not finished yet"*:
     *
     *  - **A member claiming two social categories.** Not an incomplete committee, a
     *    contradictory member — and there is no draft state in which a person is
     *    both SC and ST.
     *  - **The CGPSC Chairman's seat missing or duplicated**, and **two DPC
     *    Chairmen**. The officer cannot create or remove either through this form,
     *    so a payload saying otherwise was tampered with rather than unfinished.
     *  - **More members than the maximum.** A bound that cannot be exceeded even in
     *    a draft: the extra rows would have to be deleted again before anything
     *    could be submitted, and accepting them writes rows the rules forbid.
     *
     * What does not: **too few members**, and **an uncovered attribute**. Both are
     * "not finished yet", which is what a draft is for.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return list<string>
     */
    public function blockingFindings(DpcProposal $proposal, array $members): array
    {
        $rules = $this->rules->forProposal($proposal);
        $findings = [];

        $chairmanSeats = $this->withCapacity($members, CommitteeCapacity::CgpscChairman);
        $ordinary = $this->withCapacity($members, CommitteeCapacity::Member);

        if (count($ordinary) > $rules->maxMembers) {
            $findings[] = (string) __('dpc.committee.findings.too_many', [
                'max' => $rules->maxMembers,
                'count' => count($ordinary),
            ]);
        }

        if ($chairmanSeats === []) {
            $findings[] = (string) __('dpc.committee.findings.chairman_missing');
        }

        if (count($chairmanSeats) > 1) {
            $findings[] = (string) __('dpc.committee.findings.chairman_duplicated');
        }

        return array_merge(
            $findings,
            $this->dpcChairmanFindings($members),
            $this->socialCategoryFindings($members),
        );
    }

    /**
     * Which required attributes nobody on the committee represents.
     *
     * **Every member counts, including the Chairman's seat.** The Chairman is a
     * member of the committee, and an attribute they represent is represented at
     * the sitting — excluding them would force a department to add a member to
     * cover something already covered.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @param  list<MemberCategory>  $required
     * @return list<MemberCategory>
     */
    public function uncovered(array $members, array $required): array
    {
        return array_values(array_filter(
            $required,
            static fn (MemberCategory $category): bool => ! array_any(
                $members,
                static fn (array $member): bool => $category->claimedBy($member),
            ),
        ));
    }

    /**
     * Whether anybody on this committee belongs to a reserved social category.
     *
     * **Any one of the three satisfies it**, which is the whole difference between
     * this and {@see uncovered()}. The business asked for *"at least 1 of SC/ST/OBC"*
     * — one member, one category — and expressing that through the each-of list
     * would demand an SC member **and** an ST member **and** an OBC member, three
     * where one was asked for.
     *
     * **Every member counts, including the Chairman's seat**, for the same reason
     * {@see uncovered()} counts them: a category represented by the Chairman is
     * represented at the sitting.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    public function representsAReservedCategory(array $members): bool
    {
        return array_any(
            MemberCategory::socialCategories(),
            static fn (MemberCategory $category): bool => array_any(
                $members,
                static fn (array $member): bool => $category->claimedBy($member),
            ),
        );
    }

    /**
     * Exactly one member conducts a DPC (item 9, as clarified).
     *
     * Absence is **not** a finding here, and that is deliberate. The DPC Chairman
     * is designated by the CGPSC Chairman *after* the committee exists — the
     * register says they nominate "from the DPC members" — so refusing to save a
     * committee that has no DPC Chairman yet would make the two steps impossible to
     * perform in the order the register describes. Two of them is a finding,
     * because that is never a stage.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return list<string>
     */
    private function dpcChairmanFindings(array $members): array
    {
        $designated = array_values(array_filter(
            $members,
            static fn (array $member): bool => filter_var($member['is_dpc_chairman'] ?? false, FILTER_VALIDATE_BOOL),
        ));

        return count($designated) > 1
            ? [__('dpc.committee.findings.dpc_chairman_duplicated')]
            : [];
    }

    /**
     * A member belongs to at most one reserved social category (business
     * clarification, 2026-09-05).
     *
     * > *A Committee Member may belong to ONLY ONE reserved social category …
     * > SC + ST is not allowed. SC + OBC is not allowed. ST + OBC is not allowed.
     * > If none is selected, the member is automatically treated as UR (General).*
     *
     * **Checked per member, not per committee**, which is the one place in this
     * class where that is the right question. Everything else here asks what the
     * committee as a whole represents — a committee may and often must contain an
     * SC member *and* an ST member. What it may not contain is one person who is
     * both.
     *
     * **Reported with the member's name and their position in the list**, because
     * the form posts an array and "one member claims two categories" is not
     * actionable on a committee of six. The position is 1-based to match what the
     * officer is looking at.
     *
     * Nothing is said about a member claiming none: that is UR, which is the
     * default and not a fault. There is no UR checkbox to have failed to tick.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return list<string>
     */
    private function socialCategoryFindings(array $members): array
    {
        $findings = [];
        $position = 0;

        foreach ($members as $member) {
            $position++;

            $claimed = array_values(array_filter(
                MemberCategory::socialCategories(),
                static fn (MemberCategory $category): bool => $category->claimedBy($member),
            ));

            if (count($claimed) < 2) {
                continue;
            }

            $findings[] = __('dpc.committee.findings.social_category_conflict', [
                'position' => $position,
                'member' => trim((string) ($member['name'] ?? '')) !== ''
                    ? (string) $member['name']
                    : __('dpc.committee.findings.unnamed_member'),
                'categories' => implode(' + ', array_map(
                    static fn (MemberCategory $category): string => $category->label(),
                    $claimed,
                )),
            ]);
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     * @return array<int, array<string, mixed>>
     */
    private function withCapacity(array $members, CommitteeCapacity $capacity): array
    {
        return array_values(array_filter(
            $members,
            static fn (array $member): bool => ($member['capacity'] ?? null) === $capacity->value,
        ));
    }
}
