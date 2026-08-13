<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Validation\DpcRowRules;
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
        });
    }
}
