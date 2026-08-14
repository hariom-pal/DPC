<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Dpc\Validation\DpcRowRules;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Recording a Commission correction (PPT S33 EDIT / TRIBAL) — M7 Phase 2.
 *
 * The corrected value is validated by the **same rules M4 uses** for the same
 * field, taken from `DpcRowRules` rather than restated here. That reuse is the
 * point: three code paths — manual entry, Excel import, and now Commission
 * correction — validating one field differently is how a bad CR grade
 * eventually reaches a promotion list. A rule tightened in `DpcRowRules`
 * tightens this path too, with no second copy that can drift.
 *
 * Reading `DpcRowRules` is not a modification of frozen M4 code.
 */
class RecordCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proceeding = $this->route('proceeding');

        return $proceeding instanceof DpcProceeding
            && ($this->user()?->can('act', $proceeding) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $enabled = array_map(
            static fn (CorrectableField $field): string => $field->value,
            CorrectableField::enabled(),
        );

        return [
            'sn' => ['required', 'integer', 'min:1'],
            // An allow-list drawn from configuration, never a free-form column
            // name: accepting whatever arrives would be the difference between
            // a correction and an arbitrary write.
            'field' => ['required', 'string', Rule::in($enabled)],
            'new_value' => ['nullable', 'string', 'max:50'],
            'reason' => [
                'required', 'string',
                'min:'.(int) config('dpc.proceeding.correction_reason_min', 10),
                'max:1000',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $field = CorrectableField::from((string) $this->input('field'));

            $this->validateYear($validator, $field);
            $this->validateValue($validator, $field);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required for every correction.',
            'reason.min' => 'Please state the reason for this correction in a little more detail.',
        ];
    }

    /**
     * A per-year field without a year — or a whole-row field with one — is a
     * malformed correction, not merely an odd one.
     */
    private function validateYear(Validator $validator, CorrectableField $field): void
    {
        $year = $this->input('year');

        if (! $field->isPerYear()) {
            if ($year !== null && $year !== '') {
                $validator->errors()->add('year', 'This correction does not apply to a single year.');
            }

            return;
        }

        if ($year === null || $year === '') {
            $validator->errors()->add('year', 'A year is required for this correction.');

            return;
        }

        // M4's own year bounds, not a second opinion about them.
        $this->merge(['year' => (int) $year]);
        $this->copyErrors(
            $validator,
            'year',
            ['year' => (int) $year],
            ['year' => DpcRowRules::evaluationCrGrade()['year']],
        );
    }

    /**
     * Clearing a value is a legitimate correction: a CR grade recorded in
     * error must be removable, and "not recorded" is distinct from any grade.
     */
    private function validateValue(Validator $validator, CorrectableField $field): void
    {
        $value = $this->input('new_value');

        if ($value === null || $value === '') {
            return;
        }

        [$payload, $rules] = match ($field) {
            CorrectableField::CrGrade => [
                ['grade' => $value],
                ['grade' => DpcRowRules::evaluationCrGrade()['grade']],
            ],
            CorrectableField::LoyaltyFlag, CorrectableField::PropertyFlag => [
                // The override stores Y/N as text; M4 stores a boolean. The
                // value is mapped, then validated by M4's rule rather than by
                // a parallel one.
                ['flag' => match ($value) {
                    'Y' => true, 'N' => false, default => $value
                }],
                ['flag' => DpcRowRules::evaluationYearFlag()['flag']],
            ],
            CorrectableField::TribalAreaServiceYears => [
                ['tribal_area_service_years' => $value],
                ['tribal_area_service_years' => DpcRowRules::evaluationRow()['tribal_area_service_years']],
            ],
        };

        $this->copyErrors($validator, 'new_value', $payload, $rules);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rules
     */
    private function copyErrors(Validator $validator, string $attribute, array $payload, array $rules): void
    {
        $inner = ValidatorFactory::make($payload, $rules);

        if ($inner->fails()) {
            foreach ($inner->errors()->all() as $message) {
                $validator->errors()->add($attribute, $this->humanise($attribute, $message));
            }
        }
    }

    /**
     * M4's messages name M4's field, which means nothing on this screen.
     */
    private function humanise(string $attribute, string $message): string
    {
        if ($attribute === 'year') {
            return 'That year is outside the range the chart covers.';
        }

        return str_contains($message, 'selected')
            ? 'That is not a value this field accepts.'
            : $message;
    }
}
