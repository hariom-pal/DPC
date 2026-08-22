<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Validation\DpcRowRules;
use App\Domain\Import\Services\ExcelImportService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Working Officers + Vacancy breakdown (PPT S16) — M4 Phase 3. Both
 * `vacancy_type_id` and `reservation_category_id` must resolve to an M2
 * master row (never a hardcoded reservation/vacancy value).
 */
class SaveWorkingOfficersRequest extends FormRequest
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
        return array_merge([
            'sanctioned_post_count' => ['required', 'integer', 'min:0'],

            'vacancies' => ['array', 'max:'.(int) config('dpc.limits.vacancy_rows')],
            'vacancies.*.vacancy_type_id' => ['required', Rule::exists('vacancy_types', 'id')],
            'vacancies.*.reservation_category_id' => ['required', Rule::exists('reservation_categories', 'id')],
            'vacancies.*.count' => ['required', 'integer', 'min:0'],

            'officers' => ['array', 'max:'.(int) config('dpc.limits.working_officers')],
        ], DpcRowRules::prefix('officers.*.', DpcRowRules::workingOfficer()));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'officers.max' => 'A single save may carry at most :max working officers.',
            'vacancies.max' => 'A single save may carry at most :max vacancy rows.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $vacancies */
            $vacancies = $this->input('vacancies', []);

            $pairs = array_map(
                static fn (array $v): string => ($v['vacancy_type_id'] ?? '').'-'.($v['reservation_category_id'] ?? ''),
                $vacancies,
            );

            if (count($pairs) !== count(array_unique($pairs))) {
                $validator->errors()->add('vacancies', 'Each Vacancy Type + Reservation Category combination may appear only once — use a single row per combination.');
            }

            $this->assertVacancyArithmetic($validator, $vacancies);
        });
    }

    /**
     * The two derived-vacancy invariants (business directive, 2026-08-22).
     *
     * Checked against **this request's** officer array rather than the rows
     * already saved, because this screen saves the sanctioned strength, the
     * vacancy breakdown and the officer chart in one submission — so the numbers
     * to judge are the ones arriving, not the ones being replaced.
     *
     * The Excel import writes officers without passing through here, so
     * {@see ExcelImportService::commit()} enforces
     * the ceiling again on that path, and the submission checklist enforces both
     * once more before an Administrative Head can be asked to sign.
     *
     * @param  array<int, array<string, mixed>>  $vacancies
     */
    private function assertVacancyArithmetic(Validator $validator, array $vacancies): void
    {
        // Nothing to say about a proposal that cannot be edited. A Form Request
        // is validated before the controller authorises, so complaining here
        // would answer an edit attempt on a submitted proposal with a
        // validation redirect instead of the 403 the immutability rule owes it.
        $proposal = $this->route('dpcProposal');

        if ($proposal instanceof DpcProposal && ! $proposal->status->isEditable()) {
            return;
        }

        $sanctioned = $this->input('sanctioned_post_count');

        if (! is_numeric($sanctioned)) {
            return; // The `required|integer` rule above already said so.
        }

        $sanctioned = (int) $sanctioned;
        $working = count((array) $this->input('officers', []));
        $vacancy = $sanctioned - $working;

        if ($vacancy < 0) {
            if ((bool) config('dpc.vacancy.enforce_sanctioned_ceiling', true)) {
                $validator->errors()->add('officers', 'Working Officers cannot exceed the sanctioned strength.');
            }

            // No balance check below: there is no meaningful total to balance
            // against, and a second error about a negative figure would only
            // obscure the first.
            return;
        }

        if (! (bool) config('dpc.vacancy.enforce_classification_balance', true)) {
            return;
        }

        $classified = array_sum(array_map(
            static fn (array $v): int => (int) ($v['count'] ?? 0),
            $vacancies,
        ));

        if ($classified !== $vacancy) {
            $validator->errors()->add(
                'vacancies',
                'The sum of all vacancy types must equal the calculated total vacancy.'
                ." Sanctioned {$sanctioned} − {$working} working = {$vacancy}; the breakdown totals {$classified}.",
            );
        }
    }
}
