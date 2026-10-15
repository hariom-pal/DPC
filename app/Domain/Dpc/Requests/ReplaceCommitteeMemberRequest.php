<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Substituting one committee member for another (change register of 2026-08-26,
 * item 9e).
 *
 * **The reason is required, and it is the only field here that is.** A substitution
 * is the one committee operation that changes a record which may already have been
 * relied on — a proceeding cites the committee, and a signature attests to it. The
 * outgoing member's row is kept precisely so that "who was on this committee, and
 * why did that change" stays answerable, and a blank reason would keep the *what*
 * while losing the *why*, which is the half a tribunal asks about.
 *
 * The six composition attributes are optional, and their absence means *inherit*:
 * `DpcCommitteeService::replace()` carries the outgoing member's attributes over
 * when the payload omits them. A substitution is normally like-for-like, and a
 * committee that quietly lost its only ST member because a form defaulted to blank
 * would fail its own composition rules at the sitting.
 */
class ReplaceCommitteeMemberRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:2000'],

            // The successor, either an account the Administrator found or a person
            // recorded on the spot — the same two paths item 6 gives the officer.
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            // Chosen from the Designation master, never typed — as on the
            // composition form. The name is kept for the inherit-from-outgoing path.
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')
                ->where('status', 'active')
                ->whereNull('deleted_at')],
            'designation' => ['nullable', 'string', 'max:255'],
            // The same two shapes the composition form enforces (2026-09-06):
            // ten digits starting 6-9, and a government address.
            'mobile' => ['nullable', 'string', 'regex:/^[6-9]\\d{9}$/'],
            'email' => ['nullable', 'email', 'max:255', 'regex:/@(?:[A-Za-z0-9-]+\\.)*gov\\.in$/i'],
        ];

        foreach (DpcCommitteeMember::ATTRIBUTE_COLUMNS as $column) {
            $rules[$column] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('dpc.committee.validation.replacement_reason_required'),
            'name.required_without' => __('dpc.committee.validation.name_required'),
            'mobile.regex' => __('dpc.committee.validation.mobile_shape'),
            'email.regex' => __('dpc.committee.validation.email_domain'),
        ];
    }

    /**
     * The successor obeys the same social-category rule as anybody else (business
     * clarification, 2026-09-05).
     *
     * Checked here rather than through `CommitteeComplianceService`, which judges a
     * whole posted committee: this form posts **one** member and inherits the rest
     * from what is already saved, so there is no committee list to hand it. The
     * rule being enforced is the same one, and the message is the same string.
     *
     * A substitution that ticks none of the three is UR, exactly as elsewhere —
     * except that omitting them entirely means *inherit the outgoing member's*,
     * which is what makes this form's blank state different from the composition
     * form's. Inherited attributes were themselves validated when they were saved,
     * so nothing is re-checked here.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $claimed = array_values(array_filter(
                MemberCategory::socialCategories(),
                fn (MemberCategory $category): bool => $this->boolean($category->column()),
            ));

            if (count($claimed) < 2) {
                return;
            }

            $validator->errors()->add(MemberCategory::ScheduledCaste->column(), (string) __(
                'dpc.committee.findings.social_category_conflict',
                [
                    'position' => 1,
                    'member' => trim((string) $this->input('name', '')) !== ''
                        ? (string) $this->input('name')
                        : __('dpc.committee.findings.unnamed_member'),
                    'categories' => implode(' + ', array_map(
                        static fn (MemberCategory $category): string => $category->label(),
                        $claimed,
                    )),
                ],
            ));
        });
    }
}
