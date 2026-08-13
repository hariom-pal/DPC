<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Validation\DpcRowRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Seniority List (PPT S17) — M4 Phase 4. `reservation_category_id` must
 * resolve to an M2 master row; every other field is a plain snapshot
 * value, never validated against any live employee source.
 */
class SaveSeniorityEntriesRequest extends FormRequest
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
            ['entries' => ['array', 'max:'.(int) config('dpc.limits.seniority_entries')]],
            DpcRowRules::prefix('entries.*.', DpcRowRules::seniorityEntry()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.max' => 'A single save may carry at most :max seniority entries.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $entries */
            $entries = $this->input('entries', []);

            $numbers = array_column($entries, 'seniority_number');
            if (count($numbers) !== count(array_unique($numbers))) {
                $validator->errors()->add('entries', 'Each Seniority Number must be unique within the list.');
            }
        });
    }
}
