<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Enums\VacancyEntryEligibility;
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
}
