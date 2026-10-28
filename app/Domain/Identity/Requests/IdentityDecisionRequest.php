<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A CGPSC Super Admin identity decision (2026-10-27): correction of identity
 * details, manual verification, or forced re-verification.
 */
class IdentityDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route is the Super Admin's alone (role:admin).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $document = ['file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)];

        return match ($this->route()?->getName()) {
            'admin.users.identity.correct' => [
                'name' => ['required', 'string', 'max:255'],
                'date_of_birth' => ['required', 'date', 'before:today'],
                'gender' => ['required', 'in:M,F'],
                'reason' => ['required', 'string', 'max:1000'],
                'document' => ['nullable', ...$document],
            ],
            'admin.users.identity.manual' => [
                'reason' => ['required', 'string', 'max:1000'],
                'document' => ['required', ...$document],
            ],
            default => [
                'reason' => ['required', 'string', 'max:1000'],
            ],
        };
    }
}
