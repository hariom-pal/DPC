<?php

declare(strict_types=1);

namespace App\Domain\Committee\Enums;

use App\Domain\Dpc\Models\DpcCommitteeMember;

/**
 * A **committee composition attribute** — what a member represents on one DPC's
 * committee (change register of 2026-08-26, item 8).
 *
 * ### These are not reservation categories
 *
 * The register gives this its own heading and states it twice, because the two
 * things share three names and mean entirely different things:
 *
 *  - `ReservationCategory` is master data about the **officers being promoted**.
 *    It drives roster points and the reserved-seat arithmetic in the M5 scoring
 *    engine. Getting it wrong changes who is promoted.
 *  - This is about the **composition of the committee** — whether a woman, an SC
 *    member and a Vibhag Sachiv are present at the sitting. Getting it wrong
 *    changes whether the committee may sit.
 *
 * Nothing in this enum is read by the scoring engine, and nothing in
 * `ReservationCategory` is read here.
 *
 * ### They belong to the assignment, not to the person
 *
 * Until 2026-08-26 these were columns on a `commission_members` master, which made
 * them a standing property of an individual. The register removed that master and
 * said the attributes are *"maintained for every selected Committee Member"* and
 * *"stored only in the DPC Committee assignment"*. So {@see column()} names a
 * column on `dpc_committee_members`, and a person is not "the SC member" — they
 * are the member who represented SC **on this committee**.
 *
 * ### One member may satisfy several at once
 *
 * Six independent booleans rather than one `category` column, because the register
 * is explicit that a single member may satisfy several simultaneously — its own
 * example is a member who is Female, ST and Bhar Sadhak Sachiv together. That
 * independence is what `CommitteeComplianceService::uncovered()` exploits: it asks
 * whether the committee *as a whole* represents each required attribute, which is
 * the difference between a three-member committee satisfying five requirements and
 * needing five members.
 *
 * ### …except the three social categories, which are one choice
 *
 * **Business clarification of 2026-09-05.** SC, ST and OBC describe *which reserved
 * social category a member belongs to*, and a person belongs to at most one. So the
 * three are mutually exclusive — `SC + ST`, `SC + OBC` and `ST + OBC` are each
 * refused — while Female, Bhar Sadhak Sachiv and Vibhag Sachiv remain freely
 * combinable with each other and with whichever social category was chosen.
 *
 * **There is no UR checkbox, and there must not be one.** A member who claims none
 * of the three *is* UR (General); it is the absence of a claim rather than a claim
 * of its own. Adding a seventh box would create a state — "UR ticked *and* SC
 * ticked" — that has no meaning, and a second thing to keep in step with the other
 * three. {@see socialCategories()} is the exclusive set;
 * {@see additionalAttributes()} is the free one.
 *
 * **The two Sachiv attributes keep the register's own names.** They denote offices
 * rather than communities, the composition rules are written in those terms, and
 * translating them in the code would make the rule and its source stop matching.
 */
enum MemberCategory: string
{
    case ScheduledCaste = 'sc';
    case ScheduledTribe = 'st';
    case OtherBackwardClass = 'obc';
    case Female = 'female';

    /**
     * Bhar Sadhak Sachiv — the secretary bearing the burden of the department's
     * establishment matters.
     *
     * Renamed from the bare `bhar` of the 2026-08-24 master, because the register
     * of 2026-08-26 names it in full and a half-name invites the wrong reading.
     */
    case BharSadhakSachiv = 'bhar_sadhak_sachiv';

    /**
     * Vibhag Sachiv — the secretary of the department the promotion is in.
     *
     * **New on 2026-08-26.** The withdrawn master had no equivalent: it carried a
     * free-text `section` column, which could record which Vibhag a member came
     * from but could not answer "is a Vibhag Sachiv present", because a string is
     * not a claim.
     */
    case VibhagSachiv = 'vibhag_sachiv';

    /**
     * The column on `dpc_committee_members` holding this attribute.
     *
     * Derived from the case value rather than written out, so adding an attribute
     * means adding a case and a column and nothing else — but see
     * {@see DpcCommitteeMember::ATTRIBUTE_COLUMNS}, which
     * pins the list the model will accept, so a typo here cannot silently become a
     * mass-assignable column.
     */
    public function column(): string
    {
        return 'is_'.$this->value;
    }

    /**
     * The catalogue key for this attribute's label.
     *
     * A key rather than a literal: the portal is bilingual, and a committee's
     * composition rules are read by people who work in Hindi.
     */
    public function labelKey(): string
    {
        return 'dpc.committee.attributes.'.$this->value;
    }

    public function label(): string
    {
        return (string) __($this->labelKey());
    }

    /**
     * Whether a saved committee row represents this attribute.
     */
    public function representedBy(DpcCommitteeMember $member): bool
    {
        return (bool) $member->{$this->column()};
    }

    /**
     * Whether a *posted* committee row claims this attribute.
     *
     * Takes the raw request array rather than a model, because the compliance
     * check runs before the write — a committee is refused rather than saved and
     * then found wanting.
     *
     * @param  array<string, mixed>  $row
     */
    public function claimedBy(array $row): bool
    {
        return filter_var($row[$this->column()] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * The three reserved social categories, of which a member holds **at most
     * one** (business clarification, 2026-09-05).
     *
     * Ordered SC, ST, OBC — the order the clarification lists them and the order
     * the form shows them, so the screen and the error message agree.
     *
     * @return list<self>
     */
    public static function socialCategories(): array
    {
        return [self::ScheduledCaste, self::ScheduledTribe, self::OtherBackwardClass];
    }

    /**
     * The attributes that combine freely — with each other and with a social
     * category.
     *
     * Derived by subtraction rather than listed, so an attribute added to this
     * enum is automatically treated as combinable unless it is also added to
     * {@see socialCategories()}. The failure mode that matters is a new attribute
     * silently becoming *exclusive*; this one makes it silently become *free*,
     * which is the pre-existing behaviour of every attribute here.
     *
     * @return list<self>
     */
    public static function additionalAttributes(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $category): bool => ! $category->isSocialCategory(),
        ));
    }

    /**
     * Whether this attribute is one of the three mutually exclusive ones.
     */
    public function isSocialCategory(): bool
    {
        return in_array($this, self::socialCategories(), true);
    }

    /**
     * The social category a *posted* row claims, or null — which means UR
     * (General).
     *
     * Null rather than a `Unreserved` case, deliberately: UR is not an attribute a
     * member carries, it is what is left when none of the three is claimed. Giving
     * it a case would let it be stored, required by configuration, and ticked
     * alongside SC.
     *
     * Returns the **first** claim when a row makes several, which only happens on a
     * payload that {@see CommitteeComplianceService} is about to refuse — this is
     * for rendering, not for deciding.
     *
     * @param  array<string, mixed>  $row
     */
    public static function claimedSocialCategory(array $row): ?self
    {
        foreach (self::socialCategories() as $category) {
            if ($category->claimedBy($row)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * How a member with no social category reads on a screen.
     *
     * A catalogue key like every other label, because "UR (General)" appears on
     * Hindi screens too.
     */
    public static function unreservedLabel(): string
    {
        return (string) __('dpc.committee.attributes.ur');
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(static fn (self $category): string => $category->column(), self::cases());
    }
}
