<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule 2.1: the Administrative Head's decision on a PH claim of a submitted proposal.
 */
class VerifyPhClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('approve', $proposal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:verified,not_verified'],
            'remarks' => ['required_if:decision,not_verified', 'nullable', 'string', 'max:2000'],
        ];
    }
}
