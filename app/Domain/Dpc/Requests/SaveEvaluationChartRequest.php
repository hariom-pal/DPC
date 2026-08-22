<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\EvaluationCandidateService;
use App\Domain\Dpc\Validation\DpcRowRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Evaluation / Tabulation Chart (PPT S18) — M4 Phase 5.
 *
 * The CR grade codes and the year bound come from config (Q3), never hardcoded
 * here.
 *
 * **Since the business directive of 2026-08-22 this screen no longer accepts
 * employee identity.** The chart's officers and their ten identity fields are
 * projected from the approved Seniority List, so what is validated here is the
 * evaluation data and **one structural rule: the officers must be exactly the
 * Seniority List's.** Adding, removing or renumbering one is refused.
 *
 * No rule here computes or enforces a score, an eligibility gate, or a zone
 * size — the chart may legitimately be saved with **missing years**, which is
 * what a Circulation DPC is defined by. "Every officer must be present" and
 * "every year must be filled in" are different rules, and only the first is
 * enforced. Judgement belongs to the M5 Eligibility Engine.
 */
class SaveEvaluationChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(
            ['rows' => ['array', 'max:'.(int) config('dpc.limits.evaluation_rows')]],
            DpcRowRules::prefix('rows.*.', DpcRowRules::evaluationRow()),
            ['rows.*.cr_grades' => ['array', 'max:'.(int) config('dpc.evaluation.max_cr_years')]],
            DpcRowRules::prefix('rows.*.cr_grades.*.', DpcRowRules::evaluationCrGrade()),
            ['rows.*.loyalty_flags' => ['array']],
            DpcRowRules::prefix('rows.*.loyalty_flags.*.', DpcRowRules::evaluationYearFlag()),
            ['rows.*.property_flags' => ['array']],
            DpcRowRules::prefix('rows.*.property_flags.*.', DpcRowRules::evaluationYearFlag()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.max' => 'A single evaluation chart may hold at most :max officers.',
            'rows.*.cr_grades.max' => 'The chart scores at most :max CR years per officer (PPT S18).',
            'rows.*.cr_grades.*.grade.in' => 'Select a valid CR grade.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $this->input('rows', []);

            $serialNumbers = array_column($rows, 'sn');
            if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
                $validator->errors()->add('rows', 'Each S.N. must be unique within the evaluation chart.');
            }

            $this->assertOfficersMatchTheSeniorityList($validator, $rows);

            foreach ($rows as $index => $row) {
                foreach (['cr_grades' => 'CR', 'loyalty_flags' => 'Loyalty', 'property_flags' => 'Immovable Property'] as $key => $label) {
                    /** @var array<int, array<string, mixed>> $yearRows */
                    $yearRows = $row[$key] ?? [];
                    $years = array_column($yearRows, 'year');

                    if (count($years) !== count(array_unique($years))) {
                        $validator->errors()->add(
                            "rows.{$index}.{$key}",
                            "Each year may appear only once in the {$label} columns for this officer.",
                        );
                    }
                }
            }
        });
    }

    /**
     * The officer set must be exactly the approved Seniority List's.
     *
     * Reported as a validation error rather than left to the service's exception,
     * so the officer sees it on the screen they submitted from. The service
     * throws for the same fault, which is what protects the Excel import — it
     * never passes through here.
     *
     * **Silent on a proposal that cannot be edited.** A Form Request is
     * validated before the controller authorises, so complaining here would
     * answer an edit attempt on a submitted proposal with "the officers do not
     * match" instead of the 403 the immutability rule owes it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertOfficersMatchTheSeniorityList(Validator $validator, array $rows): void
    {
        $proposal = $this->route('dpcProposal');

        if (! $proposal instanceof DpcProposal || ! $proposal->status->isEditable()) {
            return;
        }

        foreach (app(EvaluationCandidateService::class)->violationsFor($proposal, $rows) as $violation) {
            $validator->errors()->add('rows', $violation);
        }
    }
}
