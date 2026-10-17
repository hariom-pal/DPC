<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\VacancyEntryEligibility;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolving a pending Vacancy Master entry. Which bases a reason accepts is the
 * service's (`VacancyReason::verificationBases()`).
 */
class VerifyVacancyEntryRequest extends FormRequest
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
        return [
            'decision' => ['required', Rule::in([VacancyEntryEligibility::Eligible->value, VacancyEntryEligibility::NotEligible->value])],
            'basis' => ['nullable', 'string', 'max:40'],
            'remarks' => ['required', 'string', 'max:2000'],
            'promotion_order_number' => ['nullable', 'string', 'max:120'],
            'promotion_order_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * The labels the screen shows, so a message names the field the way the form does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => __('vacancymaster.entries.decision'),
            'basis' => __('vacancymaster.entries.basis'),
            'remarks' => __('vacancymaster.entries.verification_remarks'),
            'promotion_order_number' => __('vacancymaster.entries.promotion_order_number'),
            'promotion_order_date' => __('vacancymaster.entries.promotion_order_date'),
        ];
    }

    /**
     * Each entry's verify and withdraw forms report into their own bag, so a message
     * appears under that entry and nowhere else on the page.
     */
    public static function bagFor(VacancyPositionEntry $entry): string
    {
        return 'entry_'.$entry->id;
    }

    protected function prepareForValidation(): void
    {
        $entry = $this->route('entry');

        if ($entry instanceof VacancyPositionEntry) {
            $this->errorBag = self::bagFor($entry);
        }
    }
}
