<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Services\VacancyMasterService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One row of a Vacancy Management record (2026-10-30): an officer here on
 * deputation (B1), an officer of ours on deputation elsewhere (B2), or a retirement
 * or promotion to a higher post during the year (C).
 *
 * Shape and type here; the Vacancy Year, the sanctioned ceiling and duplicates are
 * the service's, so they hold for any caller.
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
        $kindIs = fn (VacancyEntryKind $kind): \Closure => fn (): bool => $this->input('kind') === $kind->value;
        $higher = fn (): bool => $this->input('kind') === VacancyEntryKind::Anticipated->value
            && $this->input('reason') === VacancyReason::HigherPostPromotion->value;

        return [
            'kind' => ['required', Rule::in([VacancyEntryKind::DeputationIn->value, VacancyEntryKind::Deputation->value, VacancyEntryKind::Anticipated->value])],
            'officer_name' => ['required', 'string', 'max:255'],
            // Every officer with an Employee ID (2026-11-02).
            'employee_number' => ['required', 'string', 'max:60'],
            'deputation_from' => [Rule::requiredIf(fn (): bool => $kindIs(VacancyEntryKind::DeputationIn)() || $kindIs(VacancyEntryKind::Deputation)()), 'nullable', 'date'],
            // Optional (2026-11-02): without it the deputation continues and is not counted.
            'deputation_to' => ['nullable', 'date'],
            'promotion_made' => [Rule::requiredIf($kindIs(VacancyEntryKind::Deputation)), 'nullable', Rule::in(['0', '1'])],
            // B1 (2026-11-02): is the post only for deputation? Yes: not counted.
            'deputation_only_post' => [Rule::requiredIf($kindIs(VacancyEntryKind::DeputationIn)), 'nullable', Rule::in(['0', '1'])],
            'reason' => [Rule::requiredIf($kindIs(VacancyEntryKind::Anticipated)), 'nullable', Rule::in([VacancyReason::Retirement->value, VacancyReason::HigherPostPromotion->value])],
            'effective_date' => [Rule::requiredIf($kindIs(VacancyEntryKind::Anticipated)), 'nullable', 'date'],
            'order_number' => [Rule::requiredIf($higher), 'nullable', 'string', 'max:120'],
            'order_date' => [Rule::requiredIf($higher), 'nullable', 'date', 'before_or_equal:today'],
            // Not needed when the higher post's DPC is one of this portal's (2026-11-02).
            'document' => [Rule::requiredIf(fn (): bool => $higher() && ! $this->citesPortalDpc()), 'nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'higher_post_id' => [Rule::requiredIf($higher), 'nullable', 'integer'],
            'higher_dpc_reference' => [Rule::requiredIf($higher), 'nullable', 'string', 'max:60'],
            // Rule 1.2: the undertaking that the higher post's DPC is forwarded or the promotion finalized.
            'higher_post_undertaking' => $higher() ? ['accepted'] : ['nullable'],
        ];
    }

    private function citesPortalDpc(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            && VacancyMasterService::portalDpc($department->id, (string) $this->input('higher_dpc_reference', '')) !== null;
    }

    /**
     * The labels the screen shows, so a message names the field the way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => __('vacancymaster.entries.kind'),
            'officer_name' => __('vacancymaster.entries.officer_name'),
            'employee_number' => __('vacancymaster.entries.employee_number'),
            'deputation_from' => __('vacancymaster.entries.deputation_from'),
            'deputation_to' => __('vacancymaster.entries.deputation_to'),
            'promotion_made' => __('vacancymaster.entries.promotion_made'),
            'deputation_only_post' => __('vacancymaster.entries.deputation_only_post'),
            'reason' => __('vacancymaster.entries.reason'),
            'effective_date' => __('vacancymaster.entries.date'),
            'order_number' => __('vacancymaster.order_number'),
            'order_date' => __('vacancymaster.order_date'),
            'document' => __('vacancymaster.document'),
            'remarks' => __('vacancymaster.remarks'),
            'higher_post_id' => __('vacancymaster.entries.higher_post'),
            'higher_dpc_reference' => __('vacancymaster.entries.higher_dpc_reference'),
            'higher_post_undertaking' => __('vacancymaster.entries.higher_undertaking'),
        ];
    }
}
