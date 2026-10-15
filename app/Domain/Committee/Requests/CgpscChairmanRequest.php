<?php

declare(strict_types=1);

namespace App\Domain\Committee\Requests;

use App\Domain\Committee\Models\CgpscChairman;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Appointing or amending the CGPSC Chairman (change register of 2026-08-26,
 * item 9 as clarified).
 *
 * **The two operations accept different fields, and that asymmetry is the rule
 * rather than an oversight.** Appointing states who takes office and from when;
 * amending corrects the particulars of whoever already holds it. Letting an
 * amendment change `user_id` or `effective_from` would silently rewrite who chaired
 * every committee composed during that tenure — the record would say the successor
 * had been Chairman all along. A succession has to open a new tenure, which is what
 * `CgpscChairmanService::appoint()` does.
 */
class CgpscChairmanRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            /*
             | From the Designation master, never typed (DPC-MODULE-FIX-001, second
             | pass). The Chairman occupies a seat on every committee, so this
             | screen obeys the same rule the committee screen does.
             |
             | The free-text `designation` stays accepted so a record made before
             | this change still amends without losing its designation; the id wins
             | where both arrive.
             */
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')
                ->where('status', 'active')
                ->whereNull('deleted_at')],
            'designation' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],

            // Ten digits, the same shape the rest of the portal accepts.
            'mobile' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
        ];

        if ($this->isAppointment()) {
            $rules['user_id'] = [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true),
            ];

            /*
             | A future date is refused. A Chairman recorded as taking office next
             | month would become `active_flag = 1` immediately — the flag says
             | "serving", not "will serve" — and every committee composed between
             | now and then would seat somebody who had not taken office.
             |
             | Back-dating is permitted, because a Commission recording an
             | appointment after the fact is ordinary. `appoint()` refuses one that
             | precedes the incumbent's own start.
             */
            $rules['effective_from'] = ['nullable', 'date', 'before_or_equal:today'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile.regex' => __('admin.cgpsc_chairman.validation.mobile'),
            'effective_from.before_or_equal' => __('admin.cgpsc_chairman.validation.not_future'),
        ];
    }

    /**
     * Whether this request appoints somebody rather than correcting a record.
     *
     * Read from the route rather than from the payload: a client that could decide
     * which rules applied to it is a client that can opt out of them.
     */
    private function isAppointment(): bool
    {
        return ! $this->route('cgpscChairman') instanceof CgpscChairman;
    }
}
