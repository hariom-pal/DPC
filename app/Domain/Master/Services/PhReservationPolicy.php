<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ReservationPolicy;
use App\Enums\MasterStatus;
use Carbon\CarbonInterface;

/**
 * The PH reservation in promotion (rule 2.1) — the PH Reservation Policy.
 *
 * The Administrator records it under Reservation Policies like any policy: the
 * percentage of the promotion vacancies, how a decimal part becomes a whole number
 * (`floor` drops it, even .9; `ceil` rounds up), the dates it is in force, and the
 * supporting PDF. A DPC uses the active policy in force on its proposal date; with
 * none, the configured 3% with the decimal dropped.
 */
final class PhReservationPolicy
{
    public const ROUNDINGS = ['floor', 'ceil'];

    public function policyOn(CarbonInterface $date): ?ReservationPolicy
    {
        $date = $date->toDateString();

        return ReservationPolicy::query()
            ->whereHas('reservationCategory', fn ($query) => $query->where('code', (string) config('dpc.reservation.ph_category', 'PH')))
            ->where('status', MasterStatus::Active->value)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{source: ReservationPolicy|null, percentage: string, rounding: string}
     */
    public function termsFor(DpcProposal $proposal): array
    {
        $policy = $this->policyOn($proposal->proposal_date ?? now());

        return [
            'source' => $policy,
            'percentage' => $policy === null
                ? number_format((float) config('dpc.employee_details.ph_quota_percent', 3), 2, '.', '')
                : number_format((float) $policy->percentage, 2, '.', ''),
            'rounding' => $policy === null || ! in_array($policy->rounding, self::ROUNDINGS, true) ? 'floor' : $policy->rounding,
        ];
    }

    /**
     * PH posts out of `$vacancies`, as a whole number.
     *
     * Integer arithmetic on hundredths of a percent, so 3% of 100 is exactly 3 and
     * never 2.999…, and "even .9 is dropped" is exact.
     */
    public function posts(int $vacancies, string $percentage, string $rounding): int
    {
        $scaled = $vacancies * (int) round(((float) $percentage) * 100);   // posts × 10 000

        return $rounding === 'ceil' ? intdiv($scaled + 9999, 10000) : intdiv($scaled, 10000);
    }
}
