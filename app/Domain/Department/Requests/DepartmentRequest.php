<?php

declare(strict_types=1);

namespace App\Domain\Department\Requests;

use App\Enums\DepartmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Department Master maintenance (CGPSC Admin).
 *
 * The department master is the list registration draws from (PPT S6) and the
 * source of the DPC ID prefix, so `code` and `name` are unique and the code is
 * restricted to the characters a DPC ID may contain. `status` is included
 * because an admin must be able to withdraw a department from the registration
 * dropdown, but the registration flow itself is what moves a department from
 * `unregistered` to `registered` — nothing here changes that transition.
 */
class DepartmentRequest extends FormRequest
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
        $id = $this->route('department')?->id;

        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('departments', 'code')->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($id)],
            'district_id' => ['nullable', Rule::exists('districts', 'id')],
            'place' => ['nullable', 'string', 'max:255'],
            'pin' => ['nullable', 'digits:6'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', new Enum(DepartmentStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain capital letters and digits only — it becomes the DPC ID prefix.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
