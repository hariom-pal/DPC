<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Models\CrGradeValue;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\MasterStatus;
use App\Enums\ServiceClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The promotion CR rules on the Class Master, and the Eligible CR Grades they use.
 *
 * A promotion into a class comes from the same class or the class just below it.
 * Each rule applies from a DPC year until a newer one for the same pair. Rules are
 * recorded, never edited.
 */
final class PromotionCrRules
{
    /** @var array<string, PromotionCrRule|null> */
    private array $resolved = [];

    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * The rule a DPC proposal is held to: its Promotion From Post's class into its
     * Promotion To Post's class, in force in its DPC year. Asked once per pair and year.
     */
    public function ruleForProposal(DpcProposal $proposal): ?PromotionCrRule
    {
        $year = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
        $from = self::classCode($proposal->promotionFromPost?->class_level);
        $to = self::classCode($proposal->promotionToPost?->class_level);

        if ($from === null || $to === null) {
            return null;
        }

        $key = $to.'|'.$from.'|'.$year;

        if (! array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = $this->ruleFor($to, $from, $year);
        }

        return $this->resolved[$key];
    }

    /**
     * The calendar years whose CR the rule makes mandatory for a DPC year (year 1 is
     * the year just before it), newest first.
     *
     * @return list<int>
     */
    public function mandatoryCrYears(PromotionCrRule $rule, int $dpcYear): array
    {
        return $rule->years
            ->filter(fn ($year): bool => $year->years_before <= $rule->cr_years && $year->cr_mandatory)
            ->map(fn ($year): int => $dpcYear - $year->years_before)
            ->values()
            ->all();
    }

    public static function classCode(mixed $level): ?string
    {
        return match (true) {
            $level instanceof ServiceClass => $level->value,
            is_string($level) && $level !== '' => $level,
            default => null,
        };
    }

    /**
     * The classes a promotion into `$to` may come from: the same class, and the one
     * just below it in the Class Master's order.
     *
     * @return list<ClassMaster>
     */
    public function fromClasses(ClassMaster $to): array
    {
        $below = ClassMaster::query()
            ->where('status', MasterStatus::Active->value)
            ->where('sort_order', '>', $to->sort_order)
            ->orderBy('sort_order')
            ->first();

        return $below === null ? [$to] : [$to, $below];
    }

    /**
     * The Eligible CR Grades valid on 1 January of a year, highest first — a grade
     * deactivated before that day is not one of them, and one deactivated later still
     * is, so a year always answers with the grades that applied to it.
     *
     * @return list<int>
     */
    public function gradesForYear(int $year): array
    {
        return CrGradeValue::query()
            ->validOn(Carbon::create($year, 1, 1)->startOfDay())
            ->orderByDesc('value')
            ->pluck('value')
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Points of each CR grade code valid on 1 January of a year (rule 3.4), from the
     * Eligible CR Grades that name a grade code.
     *
     * @return array<string, int>
     */
    public function pointsForYear(int $year): array
    {
        return CrGradeValue::query()
            ->validOn(Carbon::create($year, 1, 1)->startOfDay())
            ->whereNotNull('grade_code')
            ->orderByDesc('effective_from')
            ->get(['grade_code', 'value'])
            ->unique('grade_code')
            ->mapWithKeys(fn (CrGradeValue $grade): array => [(string) $grade->grade_code => (int) $grade->value])
            ->all();
    }

    public function ruleFor(string $toClass, string $fromClass, int $year): ?PromotionCrRule
    {
        return PromotionCrRule::query()
            ->with('years')
            ->where('to_class', $toClass)
            ->where('from_class', $fromClass)
            ->where('effective_from_year', '<=', $year)
            ->orderByDesc('effective_from_year')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every rule for promotion into a class, newest first, by the class it comes from.
     *
     * @return Collection<int|string, Collection<int, PromotionCrRule>>
     */
    public function historyInto(ClassMaster $to): Collection
    {
        return PromotionCrRule::query()
            ->with(['years', 'recorder'])
            ->where('to_class', $to->code)
            ->orderByDesc('effective_from_year')
            ->orderByDesc('id')
            ->get()
            ->toBase()
            ->groupBy('from_class');
    }

    /**
     * @param  array<string, mixed>  $data  validated rule, with `years` keyed by years_before
     */
    public function record(ClassMaster $to, array $data, User $actor): PromotionCrRule
    {
        return DB::transaction(function () use ($to, $data, $actor): PromotionCrRule {
            $rule = PromotionCrRule::query()->create([
                'to_class' => $to->code,
                'from_class' => $data['from_class'],
                'effective_from_year' => (int) $data['effective_from_year'],
                'cr_years' => (int) $data['cr_years'],
                'loyalty_years' => (int) $data['loyalty_years'],
                'property_years' => (int) $data['property_years'],
                'qualifying_average' => $data['qualifying_average'],
                'super_seat_grade' => ($data['super_seat_grade'] ?? '') === '' ? null : (int) $data['super_seat_grade'],
                'tribal_bonus' => (int) ($data['tribal_bonus'] ?? 0),
                'remarks' => $data['remarks'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            for ($before = 1; $before <= $rule->span(); $before++) {
                $row = (array) ($data['years'][$before] ?? []);
                $rule->years()->create([
                    'years_before' => $before,
                    'cr_min_marks' => $before <= $rule->cr_years && ($row['cr_min_marks'] ?? '') !== '' ? (int) $row['cr_min_marks'] : null,
                    'cr_mandatory' => $before <= $rule->cr_years && (bool) ($row['cr_mandatory'] ?? false),
                    'loyalty_mandatory' => $before <= $rule->loyalty_years && (bool) ($row['loyalty_mandatory'] ?? false),
                    'property_mandatory' => $before <= $rule->property_years && (bool) ($row['property_mandatory'] ?? false),
                ]);
            }

            $this->timeline->record('promotion_cr_rule.recorded', $rule, sprintf(
                'Promotion rule %s → %s recorded, from DPC year %d.',
                $rule->from_class,
                $rule->to_class,
                $rule->effective_from_year,
            ), [], $actor);

            return $rule;
        });
    }
}
