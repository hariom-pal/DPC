<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Enums\VacancyReconciliation;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One reason-wise Vacancy Master entry. Controlled values only — no free-text
 * reason. The Vacancy Year boundaries and duplicate checks are the service's.
 */
class StoreVacancyEntryRequest extends FormRequest
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
        $anticipated = fn (): bool => $this->input('kind') === VacancyEntryKind::Anticipated->value;
        $reasonIs = fn (VacancyReason $reason): \Closure => fn (): bool => $this->input('reason') === $reason->value;

        return [
            'kind' => ['required', Rule::enum(VacancyEntryKind::class)],
            'reason' => ['required', Rule::enum(VacancyReason::class)],
            'vacancy_count' => ['required', 'integer', 'min:1', 'max:'.(int) config('dpc.vacancy_master.max_entry_count', 500)],
            'effective_date' => ['required', 'date'],
            'order_number' => ['required', 'string', 'max:120'],
            'order_date' => ['required', 'date', 'before_or_equal:today'],
            'document' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],

            'occupant_quota' => [Rule::requiredIf($anticipated), 'nullable', Rule::in([DpcWorkingOfficer::SOURCE_PROMOTION, DpcWorkingOfficer::SOURCE_DIRECT])],
            'reconciliation' => [Rule::requiredIf($anticipated), 'nullable', Rule::enum(VacancyReconciliation::class)],

            'higher_post_id' => [Rule::requiredIf($reasonIs(VacancyReason::HigherPostPromotion)), 'nullable', 'integer'],
            'higher_dpc_reference' => [Rule::requiredIf($reasonIs(VacancyReason::HigherPostPromotion)), 'nullable', 'string', 'max:60'],
            'promotion_order_number' => ['nullable', 'string', 'max:120'],
            'promotion_order_date' => ['nullable', 'date', 'before_or_equal:today'],

            'deputation_to' => ['nullable', 'date', 'after:effective_date'],
            'deputation_expected_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'against_sanctioned_post' => ['nullable', Rule::in(['0', '1'])],
            'cadre_details' => ['nullable', 'string', 'max:255'],
        ];
    }
}
