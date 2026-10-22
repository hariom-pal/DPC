<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Coder Instructions rule 2.2: whether a departmental enquiry, charge-sheet,
 * show-cause notice or suspension is pending against the officer, and if so which
 * (from the Sealed Cover Reason master), since when, and any remarks. The order is
 * uploaded as a document of kind `vigilance_order`.
 */
class SaveEmployeeVigilanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('update', $proposal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vigilance_pending' => ['required', 'boolean'],
            'sealed_cover_reason_id' => [
                'required_if:vigilance_pending,1', 'nullable', 'integer',
                Rule::exists('sealed_cover_reasons', 'id')->where('status', MasterStatus::Active->value)->whereNull('deleted_at'),
            ],
            'vigilance_date' => ['required_if:vigilance_pending,1', 'nullable', 'date', 'before_or_equal:today'],
            'vigilance_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sealed_cover_reason_id' => (string) __('dpc.employee_details.vigilance.reason'),
            'vigilance_date' => (string) __('dpc.employee_details.vigilance.date'),
            'vigilance_remarks' => (string) __('ui.remarks'),
        ];
    }

    protected function prepareForValidation(): void
    {
        // Nothing pending: the details are cleared, not kept.
        if (! $this->boolean('vigilance_pending')) {
            $this->merge(['sealed_cover_reason_id' => null, 'vigilance_date' => null, 'vigilance_remarks' => null]);
        }
    }
}
