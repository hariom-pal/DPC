<?php

declare(strict_types=1);

namespace App\Domain\Master\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class QualificationRequest extends FormRequest
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
        $id = $this->route('qualification')?->id;

        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('qualifications', 'code')->ignore($id)],
            // Unique on the display name too, not just the code: two rows reading
            // "B.Ed." with codes BED and B_ED would put the same qualification in
            // the dropdown twice, which is the problem this master exists to fix.
            'name_en' => ['required', 'string', 'max:255', Rule::unique('qualifications', 'name_en')->ignore($id)],
            'name_hi' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name_en.unique' => 'A qualification with this English name already exists. Edit that one instead of adding a second.',
        ];
    }
}
