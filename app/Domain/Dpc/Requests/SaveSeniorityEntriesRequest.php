<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\ProposalVacancyPosition;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Dpc\Validation\DpcRowRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Seniority List (PPT S17) — M4 Phase 4. `reservation_category_id` must
 * resolve to an M2 master row; every other field is a plain snapshot
 * value, never validated against any live employee source.
 */
class SaveSeniorityEntriesRequest extends FormRequest
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
        return array_merge(
            ['entries' => ['array', 'max:'.(int) config('dpc.limits.seniority_entries')]],
            // Rule 1.6 — compared with the proposal's DPC year below.
            ['seniority_list_as_on' => [
                Rule::requiredIf((bool) config('dpc.seniority.require_as_on_date', true)),
                'nullable',
                'date',
            ]],
            DpcRowRules::prefix('entries.*.', DpcRowRules::seniorityEntry()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.max' => 'A single save may carry at most :max seniority entries.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Step 1 before step 2: a Vacancy Management proposal's Working Officers
            // List must agree with the finalized record.
            $proposal = $this->route('dpcProposal');
            if ($proposal instanceof DpcProposal && $proposal->usesVacancyMaster()
                && ! app(ProposalVacancyPosition::class)->reconcile($proposal)['matches']) {
                $validator->errors()->add('entries', __('vacancymaster.proposal.working_list.seniority_blocked'));
            }

            /** @var array<int, array<string, mixed>> $entries */
            $entries = $this->input('entries', []);

            $numbers = array_column($entries, 'seniority_number');
            if (count($numbers) !== count(array_unique($numbers))) {
                $validator->errors()->add('entries', 'Each Seniority Number must be unique within the list.');
            }

            $employees = array_filter(array_map(
                static fn (mixed $number): string => mb_strtolower(trim((string) $number)),
                array_column($entries, 'employee_number'),
            ));
            if (count($employees) !== count(array_unique($employees))) {
                $validator->errors()->add('entries', __('dpc.seniority_list.duplicate_employee_manual'));
            }

            $this->assertListAsOn($validator);

            $this->assertZoneOfConsideration($validator, count($entries));
        });
    }

    /**
     * The list must carry exactly as many officers as the zone admits
     * (business directive, 2026-08-22).
     *
     * **Silent when no exact count can be required**, and the three reasons are
     * genuinely different rather than three ways of saying "skip":
     *
     *   - the sanctioned strength has not been entered, so there is no vacancy
     *     to size a zone from;
     *   - the class pair has no zone formula in the deck, so no cap exists to
     *     match — see VacancyCalculationService for why that is not an
     *     oversight;
     *   - the vacancy is zero, so no officer is in contention.
     *
     * Refusing the save in any of those cases would be inventing a rule the
     * deck does not state, which is the one thing this codebase does not do.
     */
    private function assertListAsOn(Validator $validator): void
    {
        $proposal = $this->route('dpcProposal');
        $stated = $this->input('seniority_list_as_on');

        if (! $proposal instanceof DpcProposal || ! is_string($stated) || $stated === '' || strtotime($stated) === false) {
            return;
        }

        $required = $proposal->seniorityReferenceDate();

        if (! Carbon::parse($stated)->isSameDay($required)) {
            $validator->errors()->add('seniority_list_as_on', __('dpc.seniority_list.as_on_mismatch', [
                'date' => $required->format('d-m-Y'),
                'year' => $proposal->dpc_year ?: $proposal->proposal_date->year,
                'stated' => Carbon::parse($stated)->format('d-m-Y'),
            ]));
        }
    }

    private function assertZoneOfConsideration(Validator $validator, int $submitted): void
    {
        if (! (bool) config('dpc.vacancy.enforce_seniority_matches_zone', true)) {
            return;
        }

        $proposal = $this->route('dpcProposal');

        if (! $proposal instanceof DpcProposal) {
            return;
        }

        /*
         * **Silent on a proposal that cannot be edited, and this is load-bearing
         * rather than tidy.** A Form Request is validated *before* the
         * controller authorises, so without this a submitted proposal would
         * answer an edit attempt with "your list is the wrong length" (302)
         * instead of "this proposal is closed" (403) — telling the officer to
         * fix something they are not permitted to touch, and quietly replacing
         * the immutability refusal with a validation one.
         *
         * Caught by `test_seniority_list_is_immutable_once_submitted`, which
         * asserts the 403 and went red the moment this check was added.
         */
        if (! $proposal->status->isEditable()) {
            return;
        }

        $required = app(VacancyCalculationService::class)->requiredSeniorityCount($proposal);

        if ($required === null || $submitted === $required) {
            return;
        }

        $validator->errors()->add(
            'entries',
            app(VacancyCalculationService::class)->seniorityCountMessage($required),
        );
    }
}
