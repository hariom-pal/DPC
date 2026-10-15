@extends('layouts.app')

@section('title', __('ui.working_officers_vacancies'))

@php
    $postIdByName = $departmentPosts->pluck('id', 'name')->all();
    $officersData = old('officers', $proposal->workingOfficers->map(fn ($o) => [
        'sn' => $o->sn,
        'status' => $o->status,
        'source' => $o->source,
        'employee_number' => $o->employee_number,
        'name' => $o->name,
        'dob' => $o->dob?->format('Y-m-d'),
        'qualification' => $o->qualification,
        'promotion_to_post' => $o->promotion_to_post,
        'promotion_from_post' => $o->promotion_from_post,

        // The snapshot stores the post *name*; the form selects by master id, so
        // a saved row is matched back to its master row to re-select it
        // (DPC-MODULE-FIX-001). No match -> blank, which is the honest answer for
        // a post whose mapping has since been superseded.
        'promotion_to_post_id' => $postIdByName[$o->promotion_to_post] ?? null,
        'promotion_from_post_id' => $postIdByName[$o->promotion_from_post] ?? null,
        'date_of_joining_service' => $o->date_of_joining_service?->format('Y-m-d'),
        'date_of_posting_on_promotion_to_post' => $o->date_of_posting_on_promotion_to_post?->format('Y-m-d'),
        'date_of_posting_on_promotion_from_post' => $o->date_of_posting_on_promotion_from_post?->format('Y-m-d'),
        'reservation_category_id' => $o->reservation_category_id,
        'seat_reservation_category_id' => $o->seat_reservation_category_id,
        'mobile' => $o->mobile,
        'roster_position' => $o->roster_position,
        'date_of_retirement' => $o->date_of_retirement?->format('Y-m-d'),
        'deputation_from' => $o->deputation_from?->format('Y-m-d'),
        'deputation_to' => $o->deputation_to?->format('Y-m-d'),
    ])->all());
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">{{ __('ui.working_officers_vacancies') }}</h1>
            <p class="text-muted small mb-3">{{ __('dpc.working_officers.subheading', ['year' => $proposal->proposal_date?->year ?? now()->year, 'post' => $proposal->promotionToPost->name]) }}</p>

            @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('currently_working_us')])

            <form method="POST" action="{{ route('dpc-proposals.working-officers.update', $proposal) }}">
                @csrf
                @method('PUT')

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        {{-- The Vacancy Summary (change register of 2026-08-27, item 8).

                             **Nothing here is typed.** The register is explicit that
                             the AO should not enter vacancy figures, so the
                             sanctioned strength comes from the Department Post
                             Mapping, the promotion percentage from the Recruitment
                             Rule, and everything else is arithmetic over those and
                             the officer chart below. The input that used to sit here
                             is gone from the form entirely, not merely disabled. --}}
                        @php $breakdown = $vacancy['breakdown']; @endphp

                        <h2 class="h6 mb-2">{{ __('dpc.working_officers.summary_heading') }}</h2>

                        @unless ($breakdown->isComplete())
                            <div class="alert alert-warning py-2">
                                <strong>{{ __('dpc.working_officers.summary_incomplete') }}</strong>
                                @if ($breakdown->sanctionedStrength === null)
                                    {{ __('dpc.working_officers.no_sanctioned_strength') }}
                                @else
                                    {{ __('dpc.working_officers.no_promotion_percentage') }}
                                @endif
                            </div>
                        @endunless

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" id="vacancy-summary">
                                <tbody>
                                    @foreach ($breakdown->summaryRows() as $row)
                                        <tr @class(['table-light' => in_array($row['key'], ['total_vacancy', 'promotion_vacancy', 'direct_recruitment_vacancy'], true)])>
                                            <th scope="row" class="fw-normal" style="width:60%">
                                                {{ __('dpc.working_officers.summary.'.$row['key']) }}
                                            </th>
                                            <td class="text-end fw-semibold">
                                                @if ($row['key'] === 'promotion_percentage')
                                                    {{ $row['value'] === null ? '—' : $row['value'].'%' }}
@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- The Zone of Consideration.

                             Kept beside the summary rather than inside it: the nine
                             figures item 8 lists are the *vacancy*, and the zone is
                             the M5 rule applied to the total. It is still derived and
                             still read-only, and it was on this screen before the
                             change register of 2026-08-27 — losing it here would have
                             quietly removed the number the seniority-list gate is
                             judged against. --}}
                        <div class="row g-3 mt-2">
                            <div class="col-md-4">
                                {!! __('dpc.working_officers.zone_label') !!}
                                <input type="text" id="derived_zone" class="form-control bg-body-secondary"
                                       value="{{ $vacancy['required_seniority'] ?? ($vacancy['zone'] && $vacancy['zone']->isUncapped() ? __('dpc.shared.no_cap') : '—') }}"
                                       readonly tabindex="-1" aria-describedby="derived_zone_help">
                                <div class="form-text" id="derived_zone_help">
                                    @if ($vacancy['zone'] && $vacancy['zone']->isUncapped())
{{ __('dpc.working_officers.no_zone_formula') }}
                                    @elseif ($vacancy['zone'] && $vacancy['zone']->size !== null)
{{ __('dpc.working_officers.zone_formula_hint', ['formula' => $vacancy['zone']->formula]) }}
                                    @else
{{ __('dpc.working_officers.available_once_saved') }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        <p class="form-text mb-0">
                            {{ __('dpc.working_officers.period_note', [
                                'from' => $breakdown->periodFrom->format('d-m-Y'),
                                'to' => $breakdown->periodTo->format('d-m-Y'),
                            ]) }}
                        </p>

                        {{-- Which officers added a vacancy, and why. Without this the
                             two counts are assertions; with it they are answerable to
                             the officer they were counted against. --}}
                        @if ($breakdown->contributors !== [])
                            <ul class="list-unstyled small text-body-secondary mt-2 mb-0">
                                @foreach ($breakdown->contributors as $contributor)
                                    <li>
                                        <span class="badge text-bg-light">{{ __('dpc.working_officers.grounds.'.$contributor['ground']) }}</span>
                                        {{ $contributor['sn'] }}. {{ $contributor['name'] }} — {{ $contributor['detail'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($vacancy['quota_overfilled'])
                            <div class="alert alert-danger py-2 mt-3 mb-0">
                                <strong>{{ __('dpc.working_officers.quota_overfilled') }}</strong>
{{ __('dpc.working_officers.quota_overfilled_explained', ['percentage' => $vacancy['breakdown']->promotionPercentage]) }}
                            </div>
                        @elseif ($vacancy['exceeds_sanctioned'])
                            <div class="alert alert-danger py-2 mt-3 mb-0">
                                <strong>{{ __('dpc.working_officers.working_officers_cannot_exceed_the_sanctioned_st') }}</strong>
{{ __('dpc.working_officers.exceeds_explained', ['working' => $vacancy['working'], 'sanctioned' => $vacancy['sanctioned']]) }}
                            </div>
                        @endif

                        <hr class="my-3">

                        {{-- The breakdown is **derived from the chart below**
                             (business directive, 2026-09-02) and is shown, not
                             asked for. It used to be a repeater the officer filled
                             in and which refused to save until its rows added back
                             up to the total this very screen had calculated. --}}
                        <h2 class="h6 mb-0">{{ __('dpc.working_officers.vacancy_breakdown') }}</h2>
                        <p class="text-muted small mb-2">{{ __('dpc.working_officers.breakdown_is_derived') }}</p>

                        @if ($derivedVacancies === [])
                            <p class="text-muted small mb-0">{{ __('dpc.working_officers.breakdown_empty') }}</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('ui.vacancy_type') }}</th>
                                            <th scope="col">{{ __('ui.reservation_category') }}</th>
                                            <th scope="col" class="text-end">{{ __('ui.count') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($derivedVacancies as $row)
                                            <tr>
                                                <td>{{ $row['type'] }}</td>
                                                <td>{{ $row['category'] }}</td>
                                                <td class="text-end">{{ $row['count'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-semibold">
                                            <td colspan="2">{{ __('dpc.working_officers.summary.total_vacancy') }}</td>
                                            <td class="text-end">{{ $vacancy['classified_total'] }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Typing officers in is off by default since 2026-09-07: the list
                     comes from the upload. Hidden by `dpc.manual_entry.working_officers`
                     rather than deleted, so *"in case we want it back"* is a setting
                     and not a rebuild — and the route is gated on the same flag,
                     because hiding a form and leaving its route open removes the
                     button rather than the feature. --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        {!! __('dpc.working_officers.officers_on_post', ['post' => e($proposal->promotionToPost->name)]) !!}
                        <p class="text-muted small mb-0">
                            {{ __('dpc.working_officers.this_count_drives_working_officers_and_total_vac') }}
                        </p>
                    </div>
                    @if (config('dpc.manual_entry.working_officers'))
                        <button type="button" class="btn btn-outline-primary btn-sm" id="add-officer">{{ __('dpc.working_officers.add_officer') }}</button>
                    @endif
                </div>
                {{-- The sanctioned-ceiling refusal attaches to `officers`. --}}
                @error('officers')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                @unless (config('dpc.manual_entry.working_officers'))
                    <p class="text-body-secondary small mb-0">{{ __('dpc.working_officers.manual_entry_off') }}</p>
                @endunless

                <div id="officers" data-seed="{{ json_encode($officersData) }}"
                     @unless (config('dpc.manual_entry.working_officers')) data-read-only @endunless></div>

                @if (config('dpc.manual_entry.working_officers'))
                    <button type="submit" class="btn btn-success mt-3">{{ __('ui.save') }}</button>
                @endif
                <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary mt-3">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>

    <template id="officer-template">
        <div class="card border-0 shadow-sm mb-2 officer-card">
            <div class="card-body row g-2">
                <div class="col-md-1"><label class="form-label">{{ __('ui.sn') }}</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="officers[__O__][sn]" required></div>
                {{-- Officer or empty chair. A vacant row carries a category and
                     nothing else, which is what makes the clear vacancy explicit
                     instead of inferred. --}}
                <div class="col-md-2"><label class="form-label">{{ __('dpc.working_officers.status') }}</label>
                    <select class="form-select form-select-sm row-status" name="officers[__O__][status]" required>
                        <option value="working">{{ __('dpc.working_officers.status_working') }}</option>
                        <option value="vacant">{{ __('dpc.working_officers.status_vacant') }}</option>
                    </select></div>
                {{-- Which of the Recruitment Rule's quotas this chair consumes. A
                     vacant row consumes none, so the field is disabled on one. --}}
                <div class="col-md-2"><label class="form-label">{{ __('dpc.working_officers.source') }}</label>
                    <select class="form-select form-select-sm" name="officers[__O__][source]">
                        <option value="promotion">{{ __('dpc.working_officers.source_promotion') }}</option>
                        <option value="deputation">{{ __('dpc.working_officers.source_deputation') }}</option>
                        <option value="direct">{{ __('dpc.working_officers.source_direct') }}</option>
                    </select></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.employee_no') }}</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][employee_number]"></div>
                <div class="col-md-3"><label class="form-label">{{ __('ui.name') }}</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][name]"></div>

                {{-- The number the officer signs in with, and where their OTP goes

                     (business instruction, 2026-09-07). Optional. --}}

                <div class="col-md-2"><label class="form-label">{{ __('ui.mobile') }}</label>

                    <input type="text" inputmode="numeric" maxlength="10" class="form-control form-control-sm"

                           name="officers[__O__][mobile]"></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.dob') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][dob]"></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.qualification') }}</label>
                    <input type="text" class="form-control form-control-sm" name="officers[__O__][qualification]"></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-row">{{ __('ui.remove_2') }}</button></div>

                <div class="col-md-3"><label class="form-label">{{ __('ui.promotion_to_post') }}</label>
                    {{-- A Post is a master entity and is never typed
                         (DPC-MODULE-FIX-001). The list is the department's active
                         Department Post mappings still in force. --}}
                    <select class="form-select form-select-sm" name="officers[__O__][promotion_to_post_id]">
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($departmentPosts as $dp)
                            <option value="{{ $dp->id }}">{{ $dp->name }}</option>
                        @endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label">{{ __('ui.promotion_from_post') }}</label>
                    {{-- A Post is a master entity and is never typed
                         (DPC-MODULE-FIX-001). The list is the department's active
                         Department Post mappings still in force. --}}
                    <select class="form-select form-select-sm" name="officers[__O__][promotion_from_post_id]">
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($departmentPosts as $dp)
                            <option value="{{ $dp->id }}">{{ $dp->name }}</option>
                        @endforeach
                    </select></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.category') }}</label>
                    <select class="form-select form-select-sm" name="officers[__O__][reservation_category_id]" required>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }}</option>@endforeach
                    </select></div>
                {{-- The **seat's** category, beside the officer's own (business
                     instruction, 2026-09-07). An SC officer selected on merit sits
                     on a General point: the box above is SC, this one is UR.
                     Optional — a department that keeps no roster leaves it blank,
                     and blank means "not recorded" rather than "same as theirs". --}}
                <div class="col-md-2"><label class="form-label">{{ __('ui.seat_category') }}</label>
                    <select class="form-select form-select-sm member-seat-category" name="officers[__O__][seat_reservation_category_id]">
                        <option value="">{{ __('ui.not_recorded') }}</option>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }}</option>@endforeach
                    </select></div>
                <div class="col-md-2"><label class="form-label">{{ __('dpc.working_officers.roster_position') }}</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="officers[__O__][roster_position]"></div>
                {{-- Items 3 and 4: the dates the retirement and deputation
                     vacancies are counted from. Both optional — most officers are
                     neither retiring in the DPC Year nor on deputation. --}}
                <div class="col-md-2"><label class="form-label">{{ __('dpc.working_officers.date_of_retirement') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_retirement]"></div>
                <div class="col-md-2"><label class="form-label">{{ __('dpc.working_officers.deputation_from') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][deputation_from]"></div>
                <div class="col-md-2"><label class="form-label">{{ __('dpc.working_officers.deputation_to') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][deputation_to]"></div>

                <div class="col-md-4"><label class="form-label">{{ __('dpc.working_officers.date_of_joining_service') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_joining_service]"></div>
                <div class="col-md-4"><label class="form-label">{{ __('ui.date_of_posting_on_promotion_to_post') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_posting_on_promotion_to_post]"></div>
                <div class="col-md-4"><label class="form-label">{{ __('ui.date_of_posting_on_promotion_from_post') }}</label>
                    <input type="date" class="form-control form-control-sm" name="officers[__O__][date_of_posting_on_promotion_from_post]"></div>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/dpc-working-officers.js') }}" defer></script>
@endpush
