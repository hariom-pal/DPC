<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Requests;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $id = $this->route('user')?->id;

        $current = $this->route('user');

        return [
            // The name is an identity field (2026-10-27): it changes on the Identity
            // page, with a reason and a recorded history — never here.
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($current): void {
                if ($current instanceof User && trim((string) $value) !== trim((string) $current->name)) {
                    $fail((string) __('identity.name_via_identity'));
                }
            }],
            // The .gov.in rule binds a new address only (2026-10-30): an account
            // that already holds another one could otherwise not be saved at all,
            // whatever was being changed.
            'email' => ['required', 'email', 'max:255', ...($this->emailChanged($current) ? ['regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i'] : []), Rule::unique('users', 'email')->ignore($id)],
            'mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')->ignore($id)],
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

    private function emailChanged(mixed $current): bool
    {
        return ! $current instanceof User
            || mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $current->email);
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
