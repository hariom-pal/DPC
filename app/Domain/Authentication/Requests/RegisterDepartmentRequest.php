<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Requests;

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
        $govEmail = 'regex:/@([A-Za-z0-9\-]+\.)*gov\.in$/i';

        return [
            'department_id' => [
                'required',
                Rule::exists('departments', 'id')->where('status', DepartmentStatus::Unregistered->value),
            ],
            'district_id' => ['required', Rule::exists('districts', 'id')],
            'place' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'digits:6'],
            'department_email' => ['required', 'email', 'max:255', $govEmail],
            'ao_name' => ['required', 'string', 'max:255'],
            'ao_designation_id' => ['required', Rule::exists('designations', 'id')],
            'ao_mobile' => ['required', 'digits:10', 'different:ah_mobile', Rule::unique('users', 'mobile')],
            'ao_email' => ['required', 'email', 'max:255', $govEmail, 'different:ah_email', Rule::unique('users', 'email')],
            'ah_name' => ['required', 'string', 'max:255'],
            'ah_designation_id' => ['required', Rule::exists('designations', 'id')],
            'ah_mobile' => ['required', 'digits:10', Rule::unique('users', 'mobile')],
            'ah_email' => ['required', 'email', 'max:255', $govEmail, Rule::unique('users', 'email')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_email.regex' => 'The department email must be an official .gov.in address.',
            'ao_email.regex' => 'The Authorized Officer email must be an official .gov.in address.',
            'ah_email.regex' => 'The Administrative Head email must be an official .gov.in address.',
        ];
    }
}
