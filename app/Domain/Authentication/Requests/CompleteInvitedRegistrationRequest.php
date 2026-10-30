<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Requests;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Step 2 of Department Admin registration: the OTPs and the officer's own password.
 * The password is chosen here, so no temporary password ever exists for this
 * account.
 */
class CompleteInvitedRegistrationRequest extends FormRequest
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
        // The last step (2026-10-27): the OTPs and DigiLocker are behind it.
        return [
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            // The Department Admin's post, from the Post (Designation) master (MoM of 2026-10-29).
            'designation_id' => ['required', 'integer', Rule::exists('designations', 'id')->where('status', MasterStatus::Active->value)->whereNull('deleted_at')],
        ];
    }
}
