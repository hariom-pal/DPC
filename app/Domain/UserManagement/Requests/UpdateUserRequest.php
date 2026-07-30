<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Requests;

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

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i', Rule::unique('users', 'email')->ignore($id)],
            'mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')->ignore($id)],
            'designation_id' => ['nullable', Rule::exists('designations', 'id')],
            'role' => ['required', Rule::in(Role::provisionableValues())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.regex' => 'The email must be an official .gov.in address.'];
    }
}
