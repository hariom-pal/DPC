<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Section F — one employee-wise document.
 *
 * PDF only, within the configured size, the same discipline as every other supporting
 * document in the portal. `mimes:pdf` checks the detected type, not the name.
 */
class UploadEmployeeDocumentRequest extends FormRequest
{
    /**
     * Authorised before validation, so a user who may not edit the proposal is
     * refused outright instead of being shown validation messages about it.
     */
    public function authorize(): bool
    {
        $proposal = $this->route('dpcProposal');

        return $proposal instanceof DpcProposal && ($this->user()?->can('update', $proposal) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', new Enum(EvaluationDocumentKind::class)],
            'year' => ['nullable', 'integer', 'min:'.(int) config('dpc.evaluation.min_year', 1950), 'max:2200'],
            'undertaking' => ['nullable', 'boolean'],
            // Rule 4.4: the AO verifies the document against its original on uploading it.
            'verified' => ['accepted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimes' => __('dpc.employee_details.documents.pdf_only'),
            'document.max' => __('dpc.employee_details.documents.too_large', ['kb' => (int) config('dpc.documents.max_kb', 10240)]),
        ];
    }
}
