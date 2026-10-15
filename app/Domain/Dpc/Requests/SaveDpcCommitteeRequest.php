<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Committee\Services\CommitteeComplianceService;
use App\Domain\Committee\Services\CommitteeMemberDirectory;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DPC Committee (PPT S15), as reshaped by the change register of 2026-08-26.
 *
 * **The composition rules are not restated here.** The size bounds, the Chairman's
 * seat and the attribute coverage all belong to `CommitteeComplianceService`, which
 * is also what the screen explains and what the service refuses on. Restating them
 * is how a form and its own validator come to disagree about what a valid committee
 * is — which happened once already, over whether the bounds included the Chairman.
 *
 * **What stays here is what only a request can check:** that each row is
 * well-formed, that the posted list does not name one person twice, and that it
 * does not try to seat the Chairman itself.
 */
class SaveDpcCommitteeRequest extends FormRequest
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
        $rules = [
            /*
             | `nullable`, not `required` (business instruction, 2026-09-06: *"remove
             | means remove"*).
             |
             | An officer who takes every member off the form is saving a draft with
             | none, which the completeness checklist then reports as incomplete —
             | the same as any other unfinished section. `required` made emptying the
             | committee the one edit that could not be saved, so the last member
             | reappeared on the next page load.
             |
             | The Chairman's seat is unaffected: it is placed by the service on
             | every save and is not the officer's to remove.
             */
            'members' => ['nullable', 'array', 'max:'.(int) config('dpc.limits.committee_members')],

            /*
             | An existing account, when the officer picked one from the search
             | (item 6). Optional: the "Create New Member" path posts an e-mail and
             | a mobile instead, and the service resolves or creates the account —
             | see `DpcCommitteeService::resolveMember()`, which repeats the
             | duplicate check server-side because the browser's answer is a
             | convenience and not a guarantee.
             */
            'members.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],

            'members.*.name' => ['required_without:members.*.user_id', 'nullable', 'string', 'max:255'],
            /*
             | Designation is chosen from the **Designation master**, never typed
             | (DPC-MODULE-FIX-001, second pass).
             |
             | `designation_id` is what the screen sends; `DpcCommitteeService`
             | resolves it to that master row's name and stores the name, so the
             | committee row keeps the point-in-time snapshot it has always held and
             | no column changes shape.
             |
             | The free-text `designation` is retained **only** so a committee saved
             | before this change, or a payload from the member-search auto-fill,
             | still resolves. The id wins where both arrive.
             */
            /*
             | **Required since 2026-09-06** (business: *"designation is also a
             | mandatory field in committee member"*).
             |
             | `required_without` rather than `required`: a committee saved before
             | this change re-posts its stored designation as free text and has no
             | id to send, and refusing it would make an existing committee
             | unsaveable until every member was reopened. One of the two must be
             | present; the id wins where both are.
             */
            'members.*.designation_id' => ['required_without:members.*.designation', 'nullable', 'integer',
                Rule::exists('designations', 'id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')],

            'members.*.designation' => ['nullable', 'string', 'max:255'],
            /*
             | Business instruction of 2026-09-06: **ten digits, digits only, on the
             | server as well as in the browser.**
             |
             | `[6-9]` first, because that is the range Indian mobile numbers start
             | at, and `max:20` — what stood here before — accepted a landline, a
             | name, or a number with a typo in it. The browser checks the same
             | shape as the officer types; this is the check that decides.
             |
             | Still `nullable`: a member seated from an existing account takes that
             | account's mobile, and an account may have none.
             */
            'members.*.mobile' => ['nullable', 'string', 'regex:/^[6-9]\\d{9}$/'],

            /*
             | A government address. Anything under `gov.in` is accepted — the
             | portal's own accounts are `@cg.gov.in` and `@cgpsc.gov.in` — and
             | nothing else is.
             |
             | The regex anchors on `@…gov.in` rather than using `ends_with`, which
             | would also accept `someone@notgov.in`.
             */
            'members.*.email' => ['nullable', 'email', 'max:255', 'regex:/@(?:[A-Za-z0-9-]+\\.)*gov\\.in$/i'],

            /*
             | Item 9: the Authorized Officer may only seat ordinary members. The
             | CGPSC Chairman's seat is placed by the system, and a payload claiming
             | it is refused here rather than silently dropped — an officer whose
             | submission was quietly altered should be told.
             */
            'members.*.capacity' => ['required', Rule::in(array_map(
                static fn (CommitteeCapacity $capacity): string => $capacity->value,
                CommitteeCapacity::selectable(),
            ))],

            'members.*.is_dpc_chairman' => ['nullable', 'boolean'],
        ];

        // Item 8: the six composition attributes, each an independent boolean.
        foreach (DpcCommitteeMember::ATTRIBUTE_COLUMNS as $column) {
            $rules['members.*.'.$column] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'members.*.capacity.in' => __('dpc.committee.validation.capacity_not_selectable'),
            'members.*.name.required_without' => __('dpc.committee.validation.name_required'),
            'members.*.designation_id.required_without' => __('dpc.committee.validation_designation_required'),
            'members.*.mobile.regex' => __('dpc.committee.validation.mobile_shape'),
            'members.*.email.regex' => __('dpc.committee.validation.email_domain'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $members */
            $members = $this->input('members', []);

            if ($members === []) {
                return;
            }

            $this->assertNobodyIsNamedTwice($validator, $members);

            $proposal = $this->route('dpcProposal');

            if (! $proposal instanceof DpcProposal) {
                return;
            }

            /*
             | **Only the findings that refuse a save**, not every finding
             | (business instruction, 2026-09-06: *"remove means remove … on save,
             | save it as a draft but keep showing it as incomplete"*).
             |
             | The composition requirements — the minimum size, and the six
             | attributes the committee must represent between them — are reported by
             | `DpcProposalCompletenessService` and block **submission**. They do not
             | block this save, because a committee that could only be saved when
             | already complete could not be edited towards completeness: removing
             | one of three members put it below the minimum, the save was refused,
             | and the removal appeared to undo itself.
             |
             | What is refused here is what is not "unfinished": a member claiming
             | two social categories, a payload tampering with the Chairman's seat,
             | two DPC Chairmen, more members than the maximum. See
             | `CommitteeComplianceService::blockingFindings()`.
             |
             | The list is given *as it will be saved* — with the Chairman's seat
             | added — because that is the committee whose validity is in question.
             */
            foreach (app(CommitteeComplianceService::class)->blockingFindings($proposal, $this->withChairmanSeat($members)) as $finding) {
                $validator->errors()->add('members', $finding);
            }
        });
    }

    /**
     * The posted list plus the seat the system will add.
     *
     * A placeholder rather than the real Chairman: this exists to make the counts
     * and the coverage check see the committee that will exist, and the Chairman's
     * identity is `DpcCommitteeService`'s to resolve. It claims no attributes,
     * which is the conservative reading — a Chairman who does represent one only
     * ever makes a committee *more* compliant, so assuming none cannot let an
     * invalid committee through.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<int, array<string, mixed>>
     */
    private function withChairmanSeat(array $members): array
    {
        return array_merge($members, [[
            'capacity' => CommitteeCapacity::CgpscChairman->value,
        ]]);
    }

    /**
     * The same person cannot sit on one committee twice.
     *
     * Checked on all three handles a member may be identified by, because a
     * duplicate entered through the "Create New Member" path carries no `user_id`
     * to collide on — it is the e-mail or the mobile that gives it away, and those
     * are exactly the two the register asks the portal to check.
     *
     * Comparison is normalised through `CommitteeMemberDirectory`, so
     * `+91 94250-12345` and `9425012345` are recognised as one number here for the
     * same reason they are recognised as one account there.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function assertNobodyIsNamedTwice(Validator $validator, array $members): void
    {
        $userIds = array_values(array_filter(array_column($members, 'user_id')));

        if (count($userIds) !== count(array_unique($userIds))) {
            $validator->errors()->add('members', __('dpc.committee.validation.duplicate_user'));
        }

        $directory = app(CommitteeMemberDirectory::class);

        $mobiles = array_values(array_filter(array_map(
            static fn (array $member): ?string => $directory->normaliseMobile((string) ($member['mobile'] ?? '')),
            $members,
        )));

        if (count($mobiles) !== count(array_unique($mobiles))) {
            $validator->errors()->add('members', __('dpc.committee.validation.duplicate_mobile'));
        }

        $emails = array_values(array_filter(array_map(
            static fn (array $member): string => mb_strtolower(trim((string) ($member['email'] ?? ''))),
            $members,
        ), static fn (string $email): bool => $email !== ''));

        if (count($emails) !== count(array_unique($emails))) {
            $validator->errors()->add('members', __('dpc.committee.validation.duplicate_email'));
        }
    }
}
