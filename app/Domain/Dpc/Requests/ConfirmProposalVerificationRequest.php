<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\ProposalVerificationItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One Rule 1.1 confirmation.
 *
 * The four items share one form shape; only the previous-DPC reconciliation carries a
 * reference to the DPC it was reconciled against. Authorization is the controller's
 * (`update` on the proposal), as for every other section of the proposal.
 */
class ConfirmProposalVerificationRequest extends FormRequest
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
        $rules = [
            'confirm' => ['accepted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->route('item') === ProposalVerificationItem::RecruitmentRuleConformity->value) {
            return $rules + ['rr_notified_on' => ['required', 'date', 'before_or_equal:today']];
        }

        if ($this->route('item') !== ProposalVerificationItem::PreviousDpcReconciliation->value) {
            return $rules;
        }

        $basis = PreviousDpcBasis::cases();

        return $rules + [
            'previous_dpc_basis' => ['required', 'string', Rule::in(array_map(fn (PreviousDpcBasis $b): string => $b->value, $basis))],
            'previous_dpc_proposal_id' => [
                Rule::requiredIf(fn (): bool => $this->input('previous_dpc_basis') === PreviousDpcBasis::InSystem->value),
                'nullable',
                'integer',
            ],
            'previous_dpc_record_id' => ['nullable', 'integer'],
            'previous_dpc_reference' => [
                Rule::requiredIf(fn (): bool => $this->input('previous_dpc_basis') === PreviousDpcBasis::Offline->value && ! $this->filled('previous_dpc_record_id')),
                'nullable',
                'string',
                'max:120',
            ],
            'previous_dpc_held_on' => [
                Rule::requiredIf(fn (): bool => $this->input('previous_dpc_basis') === PreviousDpcBasis::Offline->value && ! $this->filled('previous_dpc_record_id')),
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'minutes' => [
                Rule::requiredIf(fn (): bool => $this->input('previous_dpc_basis') === PreviousDpcBasis::Offline->value && ! $this->filled('previous_dpc_record_id')),
                'nullable',
                'file',
                'mimes:pdf',
                'max:'.(int) config('dpc.documents.max_kb', 10240),
            ],
        ];
    }

    /**
     * "No previous DPC" is a statement the department must explain.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('previous_dpc_basis') === PreviousDpcBasis::None->value
                && trim((string) $this->input('remarks')) === '') {
                $validator->errors()->add('remarks', __('dpc.verification.previous_dpc.none_needs_remarks'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => __('dpc.verification.confirm_required'),
        ];
    }
}
