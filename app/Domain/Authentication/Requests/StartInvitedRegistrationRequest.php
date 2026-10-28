<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Requests;

use App\Domain\Department\Services\DepartmentInvitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 1 of Department Admin registration: the officer's own details. The e-mail is
 * the invitation's and is not taken from the form.
 */
class StartInvitedRegistrationRequest extends FormRequest
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
        // The invited person's mobile (2026-10-27): proved by OTP next. Their name,
        // date of birth and gender come from DigiLocker.
        // The department's account may keep its own mobile when it is handed over.
        $token = $this->route('token');
        $invitation = is_string($token) ? app(DepartmentInvitationService::class)->usable($token) : null;

        return [
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('users', 'mobile')->where(
                fn ($q) => $q->whereRaw('LOWER(email) <> ?', [(string) $invitation?->email]),
            )],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile.regex' => __('department.users.validation.mobile'),
            'mobile.unique' => __('invitations.errors.mobile_taken'),
        ];
    }
}
