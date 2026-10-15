<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Requests;

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
 * **`dpc_chairman_member_id` is required too** (business clarification,
 * 2026-09-05), which makes three required fields carrying the Chairman's three
 * decisions: who executes, who presides, and by when. They are collected together
 * because they are decided together.
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
             | The member this DPC is allotted to, **from this DPC's own committee**
             | (business instruction, 2026-09-06).
             |
             | > *When we already selected the members at DPC creation time, the
             | > Chairman should allot the DPC to one of those members — showing
             | > every member is wrong.*
             |
             | Scoped in the rule rather than checked afterwards, so an id from
             | another DPC's committee fails as a validation error and never reaches
             | the service. The subquery matches on the **account**, because
             | `allotted_to` names a user who has to sign in and act, while a
             | committee row may describe somebody with no account at all — those
             | rows are simply not offered.
             |
             | `active_flag` excludes a member who has been replaced: a substitution
             | is exactly when a stale form would allot to somebody who has stood
             | down.
             */
            'allotted_to' => [
                'required',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::exists('dpc_committee_members', 'user_id')
                    ->where('dpc_proposal_id', $this->route('dpcProposal')?->id)
                    ->whereNotNull('active_flag'),
            ],

            /*
             | Who will preside over this DPC (business clarification, 2026-09-05).
             |
             | **Required, and drawn from this proposal's own committee.** The
             | clarification gives the CGPSC Chairman three decisions in one act —
             | the allottee, the DPC Chairman and the deadline — so a form that
             | collected two of them would leave the third to a screen the Chairman
             | might never open, and a DPC allotted with nobody presiding.
             |
             | Scoped to `dpc_proposal_id` in the rule itself rather than checked
             | afterwards: this committee's own rows are the only valid values, and
             | an id from another DPC's committee must fail as a validation error
             | rather than reach the service. `active_flag` excludes a member who
             | has been replaced — a substitution is exactly when a stale form
             | would name somebody who has stood down.
             |
             | The CGPSC Chairman's own seat is on every committee, so **Option A**
             | of the clarification — the Chairman presiding personally — is this
             | same field with their seat selected, not a special case.
             */
            'dpc_chairman_member_id' => [
                'required',
                'integer',
                Rule::exists('dpc_committee_members', 'id')
                    ->where('dpc_proposal_id', $this->route('dpcProposal')?->id)
                    ->whereNotNull('active_flag'),
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
            'allotted_to.required' => 'Choose the committee member this DPC is allotted to.',
            'allotted_to.exists' => 'A DPC is allotted to a serving member of its own committee who holds a portal account.',
            'dpc_chairman_member_id.required' => 'Choose who will preside over this DPC as its DPC Chairman.',
            'dpc_chairman_member_id.exists' => 'The DPC Chairman must be a serving member of this DPC\'s own committee.',
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
