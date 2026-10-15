<?php

declare(strict_types=1);

namespace App\Domain\Committee\ValueObjects;

use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Committee\Services\CommitteeRuleResolver;
use App\Domain\Master\Models\CommitteeConfiguration;

/**
 * What a DPC committee must satisfy.
 *
 * A value object rather than an array so a caller cannot read a key nobody set,
 * and so `$rules->minMembers` is an integer rather than whatever the master
 * happened to hold. Built by {@see CommitteeRuleResolver} and nowhere else.
 *
 * **`levelRule` was removed on 2026-08-26**, and its absence is a consequence
 * rather than a decision. Item 16 of the register of 2026-08-24 required a
 * committee member's post to be equal to or higher than the promotion post, and
 * ranked it using the member's post as recorded on the Commission Members master.
 * Item 5 of the register of 2026-08-26 withdrew that master, and a committee row
 * now carries a free-text designation and no registered post — so there is no
 * class to compare and the rule cannot be evaluated at all.
 *
 * Leaving the machinery in place would have been worse than removing it: a
 * `permits()` that returns true for every member, on a screen that states the rule
 * is in force, claims an enforcement that does not exist. **This is recorded as
 * BD-29** — whether the committee should capture each member's post and class so
 * the rule can be restored — and nothing here decides it.
 */
final readonly class CommitteeRules
{
    /**
     * @param  list<MemberCategory>  $requiredCategories
     */
    public function __construct(
        public int $minMembers,
        public int $maxMembers,
        public bool $chairmanRequired,
        public array $requiredCategories,
        public bool $requiresReservedCategory = false,
        public ?CommitteeConfiguration $configuration = null,
    ) {}

    /**
     * Whether this committee has any composition requirement beyond its size.
     */
    public function hasCompositionRequirements(): bool
    {
        return $this->requiresReservedCategory || $this->requiredCategories !== [];
    }

    /**
     * How the reserved-category requirement reads on a screen: "SC / ST / OBC".
     *
     * The three joined by a slash rather than a comma, because the rule is *one
     * of these*, and a comma reads as *all of these* beside the each-of list
     * printed next to it.
     */
    public function reservedCategoryLabel(): string
    {
        return implode(' / ', array_map(
            static fn (MemberCategory $c): string => $c->label(),
            MemberCategory::socialCategories(),
        ));
    }

    /**
     * How the size requirement reads on a screen.
     */
    public function sizeLabel(): string
    {
        return $this->minMembers === $this->maxMembers
            ? (string) __('dpc.committee.size_exact', ['count' => $this->minMembers])
            : (string) __('dpc.committee.size_range', ['min' => $this->minMembers, 'max' => $this->maxMembers]);
    }

    /**
     * @return list<string>
     */
    public function requiredCategoryLabels(): array
    {
        return array_map(static fn (MemberCategory $c): string => $c->label(), $this->requiredCategories);
    }
}
