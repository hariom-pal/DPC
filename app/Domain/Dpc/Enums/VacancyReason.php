<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Why a vacancy arises — the controlled list a Vacancy Master entry chooses from.
 *
 * Not a mode of recruitment: "direct recruitment" fills a post, it does not empty
 * one. A direct recruit who retires is a `Retirement` whose `occupant_quota` is
 * `direct`.
 *
 * ### Which reasons count without a further decision
 *
 * | Reason | Basis in the rules | Counted when |
 * |---|---|---|
 * | Retirement | Change register item 3; rule 1.5 (vacancy year to 31 December) | the date falls in the Vacancy Year |
 * | Deputation | Rule 2.3: deputation of more than one year against a sanctioned post | both conditions are recorded as met; otherwise pending or not eligible |
 * | Higher-post promotion | Rule 1.2 — its Hindi and English conditions differ | only after explicit verification by the Administrator |
 * | Death, Other | no rule in the DPC Understanding Document | only after explicit verification by the Administrator |
 */
enum VacancyReason: string
{
    case Retirement = 'retirement';
    case Death = 'death';
    case HigherPostPromotion = 'higher_post_promotion';
    case Deputation = 'deputation';
    case Other = 'other';

    public function label(): string
    {
        return __("vacancymaster.reasons.{$this->value}");
    }

    /**
     * The verification bases an Administrator may record to make a pending entry of
     * this reason eligible.
     *
     * @return list<string>
     */
    public function verificationBases(): array
    {
        return match ($this) {
            self::HigherPostPromotion => ['promotion_order_issued', 'higher_dpc_received_validated'],
            self::Deputation => ['deputation_conditions_confirmed'],
            self::Death, self::Other => ['commission_approved'],
            self::Retirement => [],
        };
    }
}
