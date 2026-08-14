<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Requests;

use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Opening a DPC sitting (PPT S31–S32) — M7 Phase 1.
 *
 * The preconditions that can be expressed as validation live here so the user
 * is told what is wrong rather than being handed a workflow exception. The
 * allottee check and the state machine stay in the service, where a non-HTTP
 * caller is bound by them too.
 */
class OpenProceedingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('open', DpcProceeding::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var DpcProposal|null $proposal */
            $proposal = $this->route('dpcProposal');

            if (! $proposal instanceof DpcProposal) {
                return;
            }

            // A DPC reaches the Commission only by being approved and
            // forwarded by its department. Anything else is not a DPC yet.
            if ($proposal->status !== DpcProposalStatus::Approved) {
                $validator->errors()->add(
                    'proceeding',
                    'Only a proposal approved and forwarded by its department can be taken up for a DPC.'
                );
            }
        });
    }
}
