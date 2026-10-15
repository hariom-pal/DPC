<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

use App\Domain\Cgpsc\Concerns\BoundsTheSittingByTheDeadline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The allottee's scheduling form (PPT S30) — M6 Phase 2.
 *
 * **The date is deliberately not validated against the allotment's default
 * DPC date** (architect Decision 4): the default is informational until CGPSC
 * specifies a rule, and enforcing one here would be inventing a business rule.
 *
 * A past date is likewise permitted. CGPSC may legitimately record a DPC that
 * has already taken place, and refusing it would be another invented rule.
 *
 * **The completion deadline is a different matter, and is enforced** (business
 * clarification, 2026-09-05): *"Meeting Date must always be on or before the
 * Completion Deadline assigned by the CGPSC Chairman."* Unlike the default DPC
 * date, that is a rule the Commission has stated. `SchedulingService` has refused
 * a late sitting since the register of 2026-08-26; this puts the same refusal
 * against the date box instead of in a banner after a redirect.
 */
class ScheduleDpcRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dpc_date.required' => 'Choose the date the DPC will be held.',
            'dpc_time.required' => 'Choose the time the DPC will be held.',
            'dpc_time.date_format' => 'Enter the time as HH:MM.',
        ];
    }
}
