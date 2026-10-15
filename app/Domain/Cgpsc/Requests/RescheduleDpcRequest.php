<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

use App\Domain\Cgpsc\Concerns\BoundsTheSittingByTheDeadline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Re-scheduling (PPT S30, RE-SCHEDULING) — M6 Phase 3.
 *
 * A reason is required, as it is for re-allotment: a DPC date that moved
 * without a recorded explanation is exactly the sort of change a service
 * matter will ask about later.
 *
 * As with the original schedule, the date is **not** validated against the
 * default DPC date (architect Decision 4) and a past date is permitted.
 *
 * **The completion deadline binds a re-schedule exactly as it binds the first
 * one** (business clarification, 2026-09-05): *"If rescheduled, the new meeting
 * date must also remain within the Completion Deadline."* Without that,
 * re-scheduling would be a way to opt out of the deadline entirely, and the
 * return-to-Chairman rule that depends on it would never fire.
 */
class RescheduleDpcRequest extends FormRequest
{
    use BoundsTheSittingByTheDeadline;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->assertSittingIsWithinTheDeadline($validator));
    }

    /**
     * A single date-and-time control, split into the two fields the record keeps.
     *
     * **The screen asks once** (business instruction, 2026-09-06: *"for the day the
     * actual DPC is to be held, give a date-time picker — the current one is
     * confusing and somebody who does not know will not manage it"*). It used to be
     * a date box beside a time box, which is two controls, two formats and two ways
     * to fill one in and forget the other.
     *
     * `dpc_date` and `dpc_time` are unchanged behind it: they are separate columns
     * on `dpc_schedules`, every reader of a sitting reads them separately, and the
     * rescheduling form, the service and the proceeding all speak that shape. What
     * changed is what the officer operates.
     *
     * A payload carrying the two fields directly still validates — which is what
     * lets the same request serve a form posted from a page loaded before this
     * change.
     */
    protected function prepareForValidation(): void
    {
        $at = trim((string) $this->input('dpc_at', ''));

        if ($at === '') {
            return;
        }

        // `datetime-local` posts `Y-m-d\TH:i`, and `H:i` is what the time column
        // holds. Anything else falls through to the field rules, which report it
        // against a box the officer can see.
        $parts = explode('T', $at);

        if (count($parts) !== 2) {
            return;
        }

        $this->merge([
            'dpc_date' => $parts[0],
            'dpc_time' => substr($parts[1], 0, 5),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'dpc_date' => ['required', 'date'],
            'dpc_time' => ['required', 'date_format:H:i'],
            'reschedule_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dpc_time.date_format' => 'Enter the time as HH:MM.',
            'reschedule_reason.required' => 'A reason is required when re-scheduling a DPC.',
        ];
    }
}
