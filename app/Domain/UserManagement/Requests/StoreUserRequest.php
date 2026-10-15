<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i', Rule::unique('users', 'email')],
            'mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')],
            'designation_id' => ['nullable', Rule::exists('designations', 'id')],
            'role' => ['required', Rule::in(Role::provisionableValues())],

            /*
             | Department — required for a departmental role, refused for a
             | Commission one (change items 1 and 2).
             |
             | `required_if` rather than a plain `required`: a Chairman holds no
             | department at all, and accepting one would create an assignment for
             | a role that is never departmental. `prohibited_unless` is the other
             | half of the same rule and is what makes the refusal explicit instead
             | of silently dropping the value.
             */
            'department_id' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('role'), Role::departmentalValues(), true)),
                'prohibited_unless:role,'.implode(',', Role::departmentalValues()),
                'nullable',
                Rule::exists('departments', 'id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.regex' => 'The email must be an official .gov.in address.',
            'department_id.required' => 'An Authorized Officer or Administrative Head is an officer *of a department*. Choose which one.',
            'department_id.prohibited_unless' => 'A Commission role holds no department. Leave Department blank.',
        ];
    }
}
