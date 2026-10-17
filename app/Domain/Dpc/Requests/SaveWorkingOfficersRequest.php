<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Requests;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\SanctionedStrengthSource;
use App\Domain\Dpc\Services\VacancyEngine;
use App\Domain\Dpc\Validation\DpcRowRules;
use App\Domain\Import\Services\ExcelImportService;
use App\Domain\Master\Support\ReservationMode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Working Officers + Vacancy breakdown (PPT S16) — M4 Phase 3. Both
 * `vacancy_type_id` and `reservation_category_id` must resolve to an M2
 * master row (never a hardcoded reservation/vacancy value).
 */
class SaveWorkingOfficersRequest extends FormRequest
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
        return array_merge([
            /*
             | `sanctioned_post_count` is **gone from this form** (change register of
             | 2026-08-27, item 1). It comes from the Department Post Mapping, and
             | `DpcProposalService::saveVacancies()` reads it there. A field that is
             | no longer the officer's to give is not left on the form as read-only —
             | it is not posted at all, so a crafted payload cannot set it either.
             |
             | **`vacancies` is gone the same way** (business directive, 2026-09-02).
             | The breakdown is derived from this chart by
             | `VacancyEngine::classification()`, so it is not accepted here at all —
             | a posted `vacancies` array is ignored rather than trusted.
             */
            'officers' => ['array', 'max:'.(int) config('dpc.limits.working_officers')],
        ], DpcRowRules::prefix('officers.*.', DpcRowRules::workingOfficer()));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'officers.max' => 'A single save may carry at most :max chart rows (officers and vacant posts together).',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertChartFitsTheSanctionedStrength($validator);
            $this->assertRetirementDatesFallInThePeriod($validator);
            $this->assertDeputationsOverlapThePeriod($validator);
        });
    }

    /**
     * Default every row's `status`, so a chart that names only officers still
     * validates (business directive, 2026-09-02).
     *
     * Every file and every saved chart written before this directive carries no
     * status column at all, and all of them mean the same thing: these are
     * officers. Defaulting here rather than in the rule keeps `status` genuinely
     * `required` — an explicitly wrong value is still refused.
     */
    protected function prepareForValidation(): void
    {
        $officers = (array) $this->input('officers', []);
        $mode = app(ReservationMode::class);

        if ($officers === []) {
            return;
        }

        foreach ($officers as $index => $officer) {
            if (! is_array($officer)) {
                continue;
            }

            if (($officer['status'] ?? '') === '') {
                $officers[$index]['status'] = DpcWorkingOfficer::STATUS_WORKING;
            }

            // And `promotion` for a working row with no source named — the only
            // value a 100%-promotion post allows, and what every pre-directive
            // chart is.
            if ($officers[$index]['status'] === DpcWorkingOfficer::STATUS_WORKING
                && ($officer['source'] ?? '') === '') {
                $officers[$index]['source'] = DpcWorkingOfficer::SOURCE_PROMOTION;
            }

            // No category-wise reservation: the screen asks for none, and every
            // row belongs to the one pool.
            if (! $mode->categoryWise()) {
                $officers[$index]['reservation_category_id'] = $mode->poolCategoryId();
                $officers[$index]['seat_reservation_category_id'] = null;
            }
        }

        $this->merge(['officers' => $officers]);
    }

    /**
     * The vacancy invariants (business directive 2026-08-22, restated by the
     * change register of 2026-08-27).
     *
     * Checked against **this request's** officer array rather than the rows already
     * saved, because this screen saves the vacancy breakdown and the officer chart
     * in one submission — so the numbers to judge are the ones arriving, not the
     * ones being replaced.
     *
     * **The balance is now against Total Vacancy, not Base Vacancy.** Items 3 to 5
     * add a retirement and a deputation vacancy to the base, and the scoring engine
     * fills seats from the sum of these very rows — so balancing against the base
     * would leave the retirement and deputation seats unfillable.
     *
     * The Excel import writes officers without passing through here, so
     * {@see ExcelImportService::commit()} enforces the ceiling again on that path,
     * and the submission checklist enforces it once more before an Administrative
     * Head can be asked to sign.
     */
    private function assertChartFitsTheSanctionedStrength(Validator $validator): void
    {
        if (! (bool) config('dpc.vacancy.enforce_sanctioned_ceiling', true)) {
            return;
        }

        $proposal = $this->editableProposal();

        if ($proposal === null) {
            return;
        }

        $sanctioned = app(SanctionedStrengthSource::class)->forProposal($proposal);

        if ($sanctioned === null) {
            // A master-data gap, not a validation failure. The screen says which
            // mapping is incomplete; refusing the save here would stop the officer
            // recording their chart while they wait for the Administrator.
            return;
        }

        /*
         | **The ceiling is now on the whole chart, not on its officers.**
         |
         | Since the business directive of 2026-09-02 a row is either an officer or
         | a vacant post, and together they describe the establishment. More rows
         | than the post is sanctioned for therefore means the chart claims chairs
         | that do not exist — whether the excess is people or empty seats.
         */
        $officers = (array) $this->input('officers', []);
        $rows = count($officers);

        if ($rows > $sanctioned) {
            $validator->errors()->add('officers', __('dpc.working_officers.validation.exceeds_sanctioned', [
                'working' => $rows,
                'sanctioned' => $sanctioned,
            ]));
        }
    }

    /**
     * Item 9: *"Retirement Date must fall between 1 January and 30 November."*
     *
     * **Why refusing a December date is not losing information.** The column exists
     * so the engine can count the vacancy a retirement frees, and item 3 counts only
     * retirements inside the window — a December retirement adds no vacancy to this
     * DPC Year, so leaving the field blank records exactly as much as filling it
     * would. The message says so, rather than leaving the officer guessing why a
     * true date was refused.
     *
     * Whether December retirements should be recorded for information is **BD-35**.
     */
    private function assertRetirementDatesFallInThePeriod(Validator $validator): void
    {
        $proposal = $this->editableProposal();

        if ($proposal === null) {
            return;
        }

        [$from, $to] = app(VacancyEngine::class)->period($proposal);

        foreach ((array) $this->input('officers', []) as $index => $officer) {
            $date = $officer['date_of_retirement'] ?? null;

            if (! is_string($date) || $date === '') {
                continue;
            }

            try {
                $parsed = Carbon::parse($date);
            } catch (\Throwable) {
                continue; // The `date` rule above already said so.
            }

            if (! $parsed->betweenIncluded($from, $to)) {
                $validator->errors()->add(
                    "officers.{$index}.date_of_retirement",
                    __('dpc.working_officers.validation.retirement_outside_period', [
                        'from' => $from->format('d-m-Y'),
                        'to' => $to->format('d-m-Y'),
                    ]),
                );
            }
        }
    }

    /**
     * Item 9: *"Deputation period must overlap the vacancy period."*
     *
     * Applied only to rows that record a deputation at all. An officer who is not
     * on deputation leaves both dates blank, which is the ordinary case and not a
     * failure.
     */
    private function assertDeputationsOverlapThePeriod(Validator $validator): void
    {
        $proposal = $this->editableProposal();

        if ($proposal === null) {
            return;
        }

        [$from, $to] = app(VacancyEngine::class)->period($proposal);

        foreach ((array) $this->input('officers', []) as $index => $officer) {
            $start = $officer['deputation_from'] ?? null;

            if (! is_string($start) || $start === '') {
                continue;
            }

            try {
                $startsOn = Carbon::parse($start);
                $endsOn = ($officer['deputation_to'] ?? null) === null || $officer['deputation_to'] === ''
                    ? null
                    : Carbon::parse((string) $officer['deputation_to']);
            } catch (\Throwable) {
                continue;
            }

            // The same overlap test the engine applies — see
            // `DpcWorkingOfficer::isOnDeputationDuring()`. A null end date means
            // still on deputation, which always overlaps.
            $overlaps = ! $startsOn->gt($to) && ($endsOn === null || $endsOn->gte($from));

            if (! $overlaps) {
                $validator->errors()->add(
                    "officers.{$index}.deputation_from",
                    __('dpc.working_officers.validation.deputation_outside_period', [
                        'from' => $from->format('d-m-Y'),
                        'to' => $to->format('d-m-Y'),
                    ]),
                );
            }
        }
    }

    /**
     * The proposal this request is for, but only while it may still be edited.
     *
     * A Form Request is validated before the controller authorises, so complaining
     * about a submitted proposal here would answer an edit attempt with a validation
     * redirect instead of the 403 the immutability rule owes it.
     */
    private function editableProposal(): ?DpcProposal
    {
        $proposal = $this->route('dpcProposal');

        if (! $proposal instanceof DpcProposal || ! $proposal->status->isEditable()) {
            return null;
        }

        return $proposal;
    }
}
