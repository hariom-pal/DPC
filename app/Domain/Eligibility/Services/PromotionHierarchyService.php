<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Master\Models\DepartmentPost;
use App\Enums\ServiceClass;

/**
 * Whether a promotion actually goes *up* (change register of 2026-08-27, item 11).
 *
 * > *Promotion shall always be from a lower post to a higher post. Validate using
 * > Class, Pay Scale, Grade Pay. Reject — Higher to Lower, Higher Pay Scale to Lower
 * > Pay Scale, Higher Grade Pay to Lower Grade Pay.*
 *
 * ### Three independent checks, and each may be unanswerable
 *
 * The register names three comparisons, so this makes three. Each is skipped when
 * either side lacks the value:
 *
 * | Check | Compares | Skipped when |
 * |---|---|---|
 * | Class | `ServiceClass` rank — I above II above III | never; a post always has a class |
 * | Pay Scale | `pay_scales.level` | either scale has no level recorded |
 * | Grade Pay | `pay_scales.grade_pay` | either scale has no grade pay recorded |
 *
 * **Skipping a missing value is the deliberate choice, and it cuts the other way
 * from what it looks like.** A pay scale registered before this register has no
 * `level` and no `grade_pay`; refusing every promotion out of it would turn an
 * incomplete master into a room full of ineligible officers, on the day the
 * migration ran. So an unanswerable check is *not* a failure — and
 * {@see verdict()} reports which checks it was able to make, so a reader can see
 * that the pay scale was never compared rather than assuming it passed.
 *
 * **Equal is not higher, and is not refused either — except within one class.**
 * The register forbids *higher to lower*, and says nothing about a promotion within
 * one scale or one grade pay, so only a strict decrease fails those two checks.
 *
 * Business added the one exception on 2026-09-06:
 *
 * > *promotion is always from a lower grade to a higher grade, never the other way.
 * > The one exemption is Class-I to Class-I, and that is allowed only to a higher
 * > pay scale.*
 *
 * So a **same-class** pair is held to a stricter standard than a cross-class one:
 * it must carry a strictly higher pay scale, because a move that changes neither
 * the class nor the scale is not a promotion at all. Cross-class upward pairs are
 * untouched — Class-III into Class-II on the same scale is still a promotion,
 * because the class itself went up.
 *
 * **That check is skipped when either scale has no `level`**, for the same reason
 * the scale comparison below is: an incomplete master must not become a room full
 * of ineligible officers. {@see verdict()} reports it as skipped so a reader can
 * see it was never asked rather than assume it passed.
 *
 * Pure: no database, no clock, no configuration. It is handed two posts and answers.
 */
class PromotionHierarchyService
{
    /**
     * Assess a post pair.
     *
     * @return array{valid: bool, failures: list<string>, checked: list<string>, skipped: list<string>}
     */
    public function verdict(DepartmentPost $from, DepartmentPost $to): array
    {
        $failures = [];
        $checked = [];
        $skipped = [];

        // ---- Class -------------------------------------------------------
        $fromClass = $this->rank($from->class_level);
        $toClass = $this->rank($to->class_level);

        $checked[] = 'class';

        if ($toClass < $fromClass) {
            $failures[] = sprintf(
                'Promotion is from Class-%s into Class-%s, which is a demotion. A promotion must be to an '
                .'equal or higher class.',
                $from->class_level->value,
                $to->class_level->value,
            );
        }

        // ---- Pay Scale ---------------------------------------------------
        $fromLevel = $from->payScale?->level;
        $toLevel = $to->payScale?->level;

        if ($fromLevel === null || $toLevel === null) {
            $skipped[] = 'pay_scale';
        } else {
            $checked[] = 'pay_scale';

            if ($toLevel < $fromLevel) {
                $failures[] = sprintf(
                    'Promotion is from pay scale %s into %s, which is a lower scale.',
                    // Both scales are known to carry a level to have reached here,
                    // so neither relation is null and the label is always present.
                    $from->payScale->label,
                    $to->payScale->label,
                );
            }
        }

        // ---- Same class: the pay scale has to do the promoting ------------
        if ($toClass === $fromClass) {
            if ($fromLevel === null || $toLevel === null) {
                $skipped[] = 'same_class_pay_scale';
            } else {
                $checked[] = 'same_class_pay_scale';

                if ($toLevel <= $fromLevel) {
                    $failures[] = sprintf(
                        'Promotion is from Class-%s into Class-%s on the same pay scale (%s). A promotion '
                        .'within one class must be to a higher pay scale.',
                        $from->class_level->value,
                        $to->class_level->value,
                        // Both scales carry a level to have reached here, so the
                        // relation is never null.
                        $from->payScale->label,
                    );
                }
            }
        }

        // ---- Grade Pay ---------------------------------------------------
        $fromGrade = $from->payScale?->grade_pay;
        $toGrade = $to->payScale?->grade_pay;

        if ($fromGrade === null || $toGrade === null) {
            $skipped[] = 'grade_pay';
        } else {
            $checked[] = 'grade_pay';

            if ($toGrade < $fromGrade) {
                $failures[] = sprintf(
                    'Promotion is from grade pay %s into %s, which is lower.',
                    number_format($fromGrade),
                    number_format($toGrade),
                );
            }
        }

        return [
            'valid' => $failures === [],
            'failures' => $failures,
            'checked' => $checked,
            'skipped' => $skipped,
        ];
    }

    /**
     * Whether this pair is a lawful promotion at all.
     */
    public function permits(DepartmentPost $from, DepartmentPost $to): bool
    {
        return $this->verdict($from, $to)['valid'];
    }

    /**
     * How senior a class is, as a number that can be compared.
     *
     * Class-I is the most senior, so the mapping inverts the numeral. Written out
     * rather than derived from the enum's declaration order, because relying on that
     * order would make reordering the enum silently change which promotions are
     * lawful.
     */
    private function rank(ServiceClass $class): int
    {
        return match ($class) {
            ServiceClass::I => 3,
            ServiceClass::II => 2,
            ServiceClass::III => 1,
        };
    }
}
