<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Working strength as on 1 January, for one Vacancy Management record: one figure,
 * our own officers on the post (2026-10-30). The order and its PDF are optional.
 *
 * Shape and type here; the rules that need the position (sanctioned ceiling,
 * correction reason on a second save) are the
 * service's, so they hold for any caller.
 */
class SaveWorkingStrengthRequest extends FormRequest
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
            'working_total' => ['required', 'integer', 'min:0', 'max:100000'],
            'order_number' => ['nullable', 'string', 'max:120'],
            'order_date' => ['nullable', 'date', 'before_or_equal:today'],
            'document' => ['nullable', 'file', 'mimes:pdf', 'max:'.(int) config('dpc.documents.max_kb', 10240)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'correction_reason' => ['nullable', 'string', 'max:2000'],
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
            'working_total' => __('vacancymaster.working.total'),
            'order_number' => __('vacancymaster.order_number'),
            'order_date' => __('vacancymaster.order_date'),
            'document' => __('vacancymaster.document'),
            'remarks' => __('vacancymaster.remarks'),
            'correction_reason' => __('vacancymaster.working.correction_reason'),
        ];
    }
}
