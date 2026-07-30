<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\DepartmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDepartmentRequest extends FormRequest
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
            'department_id' => [
                'required',
                Rule::exists('departments', 'id')->where('status', DepartmentStatus::Unregistered->value),
            ],
            'district' => ['required', 'string', 'max:255'],
            'place' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'digits:6'],
            'email' => [
                'required', 'email', 'max:255',
                'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i',
                Rule::unique('users', 'email'),
            ],
            'ao_name' => ['required', 'string', 'max:255'],
            'ao_designation' => ['required', 'string', 'max:255'],
            'ao_mobile' => ['required', 'digits:10', 'different:ah_mobile', Rule::unique('users', 'mobile')],
            'ah_name' => ['required', 'string', 'max:255'],
            'ah_designation' => ['required', 'string', 'max:255'],
            'ah_mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.regex' => 'The department email must be an official .gov.in address.',
        ];
    }
}
