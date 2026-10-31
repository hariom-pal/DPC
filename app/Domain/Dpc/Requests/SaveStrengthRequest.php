<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sanctioned and working strength together, on one screen with one sanctioning
 * order (2026-10-31).
 *
 * Shape and type here. The order and its PDF are needed only when the sanctioned
 * figure is new or changes, and a correction needs a reason: those depend on what is
 * already recorded, so they are the service's rules.
 */
class SaveStrengthRequest extends FormRequest
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
            'sanctioned_total' => ['required', 'integer', 'min:0', 'max:100000'],
            'working_total' => ['required', 'integer', 'min:0', 'max:100000'],
            'order_number' => ['nullable', 'string', 'max:120'],
            'order_date' => ['nullable', 'date', 'before_or_equal:today'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'correction_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sanctioned_total' => __('vacancymaster.sanctioned.total'),
            'working_total' => __('vacancymaster.pm.working_own'),
            'order_number' => __('vacancymaster.order_number'),
            'order_date' => __('vacancymaster.order_date'),
            'document' => __('vacancymaster.document'),
            'remarks' => __('vacancymaster.remarks'),
            'correction_reason' => __('vacancymaster.sanctioned.correction_reason'),
        ];
    }
}
