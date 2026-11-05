<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Requests;

use App\Domain\Master\Models\Designation;
use App\Enums\Role;
use App\Support\OfficialEmail;
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
            'email' => ['required', 'email', 'max:255', OfficialEmail::RULE, Rule::unique('users', 'email')->withoutTrashed()],
            'mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')->withoutTrashed()],
            // From the Designation master only, and required for the department's
            // own officers: Department Admin, AO and AH (2026-11-04).
            'designation_id' => [Rule::requiredIf(fn (): bool => in_array($this->input('role'), self::officerRoles(), true)), 'nullable', 'integer', Designation::masterRule()],
            'role' => ['required', Rule::in(Role::provisionableValues())],

            // Checked against DigiLocker at first sign-in, for the roles that must
            // verify their identity (2026-10-27).
            'date_of_birth' => [Rule::requiredIf(fn (): bool => in_array($this->input('role'), (array) config('dpc.identity.roles'), true)), 'nullable', 'date', 'before:today', 'after:1940-01-01'],
            'gender' => [Rule::requiredIf(fn (): bool => in_array($this->input('role'), (array) config('dpc.identity.roles'), true)), 'nullable', 'in:M,F'],

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
            'email.regex' => 'The email must be an official .gov.in or .nic.in address.',
            'department_id.required' => 'An Authorized Officer or Administrative Head is an officer *of a department*. Choose which one.',
            'department_id.prohibited_unless' => 'A Commission role holds no department. Leave Department blank.',
        ];
    }

    /**
     * The roles whose designation is required (2026-11-04).
     *
     * @return list<string>
     */
    public static function officerRoles(): array
    {
        return [Role::DepartmentAdmin->value, Role::AuthorizedOfficer->value, Role::AdministrativeHead->value];
    }
}
