<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

use App\Domain\Authentication\Models\User;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Chairman's allotment / re-allotment form (PPT S28) — M6 Phase 1.
 *
 * `default_dpc_date` is **optional**: it is manual Chairman input (architect
 * Decision 2), and requiring it would itself be an invented business rule. The
 * PPT shows the column populated but states no rule that it must be.
 *
 * `allotted_to` is validated against users actually holding a CGPSC role
 * rather than against a fixed list of five — the M1–M5 columns on S28 are a
 * layout, not a limit (architect Decision 1).
 *
 * **`complete_by` is required** (change register of 2026-08-26, item 9 as
 * clarified). The register names it as one of three facts an allotment must
 * record, and the rule that follows from it — an unconcluded DPC returns to the
 * Chairman on that date — cannot operate on an allotment that has none. An
 * optional deadline would be a deadline most allotments did not have.
 *
 * **The allottee is the DPC Chairman** (business instruction, 2026-11-17): the
 * form used to ask for both separately, and they are one person. Who executes and
 * presides, and by when, are the Chairman's two decisions.
 */
class AllotDpcRequest extends FormRequest
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
            /*
             | The person this DPC is allotted to, who is also its DPC Chairman
             | (business instruction, 2026-11-17: "Allot to" and "DPC Chairman" are
             | one choice). An active Commission account holding Member, CGPSC or
             | Chairman, CGPSC — not a DPC committee member. The service checks the
             | same rule again, for callers that do not come through this form.
             */
            'allotted_to' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $ok = is_numeric($value) && User::role(self::allottableRoles())
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $ok) {
                        $fail('A DPC is allotted to an active Member, CGPSC or the Chairman, CGPSC.');
                    }
                },
            ],

            /*
             | **One date, not two** (business instruction, 2026-09-06: *"when the
             | CGPSC Chairman allots, two dates appear — there should be one"*).
             |
             | The form used to collect a *suggested* sitting date beside the
             | deadline. Two dates on one row, one required and one not, with no
             | screen saying how they differed — and the suggested one bound nobody:
             | the allottee schedules the sitting themselves, and the only date that
             | governs anything is the deadline. `default_dpc_date` is no longer
             | collected; the column stays, because allotments already made carry a
             | value in it and a record does not stop being true when a form changes.
             |
             | The allotment's own date is today by construction — `allotted_at` is
             | stamped by the service — so it is not asked for either.
             |
             | `after_or_equal:today` because an allotment overdue the moment it is
             | made tells the member nothing and would be swept straight back to the
             | Chairman by `returnExpired()`.
             */
            'complete_by' => ['required', 'date', 'after_or_equal:today'],
            'reallotment_reason' => [
                Rule::requiredIf(fn (): bool => $this->routeIs('cgpsc.allotments.reallot')),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allotted_to.required' => 'Choose the DPC Chairman this DPC is allotted to.',
            'reallotment_reason.required' => 'A reason is required when re-allotting a DPC.',
            'complete_by.required' => 'State the date this DPC must be concluded by.',
            'complete_by.after_or_equal' => 'The completion date cannot be in the past.',
        ];
    }

    /**
     * Roles a DPC may be allotted to. Resolved from the role, not hardcoded.
     *
     * @return list<string>
     */
    public static function allottableRoles(): array
    {
        return [Role::Member->value, Role::Chairman->value];
    }
}
