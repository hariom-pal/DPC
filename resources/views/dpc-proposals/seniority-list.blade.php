@extends('layouts.app')

@section('title', __('ui.seniority_list'))

@php
    $postIdByName = $departmentPosts->pluck('id', 'name')->all();
    $entriesData = old('entries', $proposal->seniorityEntries->map(fn ($e) => [
        'seniority_number' => $e->seniority_number,
        'employee_number' => $e->employee_number,
        'name' => $e->name,
        'dob' => $e->dob?->format('Y-m-d'),
        'qualification' => $e->qualification,
        'promotion_to_post' => $e->promotion_to_post,
        'promotion_from_post' => $e->promotion_from_post,

        // The snapshot stores the post *name*; the form selects by master id, so
        // a saved row is matched back to its master row to re-select it
        // (DPC-MODULE-FIX-001). No match -> blank, which is the honest answer for
        // a post whose mapping has since been superseded.
        'promotion_to_post_id' => $postIdByName[$e->promotion_to_post] ?? null,
        'promotion_from_post_id' => $postIdByName[$e->promotion_from_post] ?? null,
        'reservation_category_id' => $e->reservation_category_id,
        'seat_reservation_category_id' => $e->seat_reservation_category_id,
        'mobile' => $e->mobile,
        'date_of_joining_service' => $e->date_of_joining_service?->format('Y-m-d'),
        'date_of_posting_on_promotion_to_post' => $e->date_of_posting_on_promotion_to_post?->format('Y-m-d'),
        'date_of_posting_on_promotion_from_post' => $e->date_of_posting_on_promotion_from_post?->format('Y-m-d'),
    ])->all());
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">{{ __('ui.seniority_list') }}</h1>
            <p class="text-muted small mb-3">{{ __('dpc.seniority_list.lead', ['post' => $proposal->promotionFromPost->name]) }}</p>

            {{-- The list must carry exactly the zone's officer count. Stated
                 here, before the officer starts typing, rather than only as a
                 refusal afterwards (business directive, 2026-08-22). --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <div class="row g-3 text-center">
                        <div class="col-6 col-md-3">
                            <div class="text-muted small">{{ __('dpc.seniority_list.sanctioned_posts') }}</div>
                            <div class="h5 mb-0">{{ $vacancy['sanctioned'] ?? '—' }}</div>
                        </div>
                        <div class="col-6 col-md-3">
                            {!! __('dpc.shared.working_officers_badge') !!}
                            <div class="h5 mb-0">{{ $vacancy['working'] }}</div>
                        </div>
                        <div class="col-6 col-md-3">
                            {!! __('dpc.shared.total_vacancy_badge') !!}
                            <div class="h5 mb-0">{{ $vacancy['total_vacancy'] ?? '—' }}</div>
                        </div>
                        <div class="col-6 col-md-3">
                            {!! __('dpc.shared.zone_badge') !!}
                            <div class="h5 mb-0">
                                {{ $vacancy['required_seniority'] ?? ($vacancy['zone'] && $vacancy['zone']->isUncapped() ? __('dpc.shared.no_cap') : '—') }}
                            </div>
                        </div>
                    </div>

                    @if ($vacancy['required_seniority'] !== null)
                        <hr class="my-3">
                        @if ($vacancy['seniority_entered'] === $vacancy['required_seniority'])
                            <p class="mb-0 small text-success">
{!! __('dpc.seniority_list.matches_zone', ['count' => $vacancy['seniority_entered'], 'formula' => e($vacancy['zone']->formula)]) !!}
                            </p>
                        @else
                            <p class="mb-0 small">
{!! __('dpc.seniority_list.must_contain', [
                                'required' => $vacancy['required_seniority'],
                                'formula' => e($vacancy['zone']->formula),
                                'actual' => $vacancy['seniority_entered'],
                            ]) !!}
                            </p>
                        @endif
                    @elseif ($vacancy['zone'] && $vacancy['zone']->isUncapped())
                        <hr class="my-3">
                        <p class="mb-0 small text-muted">
{{ __('dpc.seniority_list.uncapped_zone', ['from' => $proposal->promotionFromPost->class_level->label(), 'to' => $proposal->promotionToPost->class_level->label()]) }}
                        </p>
                    @elseif ($vacancy['sanctioned'] === null)
                        <hr class="my-3">
                        <p class="mb-0 small text-muted">
{!! __('dpc.seniority_list.enter_sanctioned_first', [
                                'link' => '<a href="'.e(route('dpc-proposals.working-officers', $proposal)).'">'
                                    .e(__('dpc.seniority_list.working_officers_amp_vacancies')).'</a>',
                            ]) !!}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Rule 1.6: the list's "as on" date, derived from the DPC year. --}}
            <div class="alert {{ $proposal->seniority_list_as_on?->isSameDay($proposal->seniorityReferenceDate()) ? 'alert-success' : 'alert-info' }} py-2 small" id="seniority-as-on">
                <div>{{ __('dpc.seniority_list.as_on_required', ['date' => $proposal->seniorityReferenceDate()->format('d-m-Y')]) }}</div>
                @if ($proposal->seniority_list_as_on === null)
                    @if ($proposal->seniorityEntries->isNotEmpty())
                        <div>{{ __('dpc.seniority_list.as_on_not_recorded', ['date' => $proposal->seniorityReferenceDate()->format('d-m-Y')]) }}</div>
                    @endif
                @elseif ($proposal->seniority_list_as_on->isSameDay($proposal->seniorityReferenceDate()))
                    <div>{{ __('dpc.seniority_list.as_on_recorded', ['date' => $proposal->seniority_list_as_on->format('d-m-Y')]) }}</div>
                @else
                    <div>{{ __('dpc.seniority_list.as_on_stale', [
                        'stated' => $proposal->seniority_list_as_on->format('d-m-Y'),
                        'year' => $proposal->dpc_year ?: $proposal->proposal_date->year,
                        'date' => $proposal->seniorityReferenceDate()->format('d-m-Y'),
                    ]) }}</div>
                @endif
                @if ($proposal->seniorityEntries->isNotEmpty())
                    <div class="mt-1"><a href="{{ route('dpc-proposals.employees.index', $proposal) }}">{{ __('dpc.employee_details.open_list') }}</a></div>
                @endif
            </div>

            @if ($workingListBlocked)
                {{-- Step 1 first: the Working Officers List must agree with Vacancy Management. --}}
                <div class="alert alert-warning" id="seniority-blocked">
                    {{ __('vacancymaster.proposal.working_list.seniority_blocked') }}
                    <a href="{{ route('dpc-proposals.working-officers', $proposal) }}">{{ __('vacancymaster.proposal.working_list.open') }}</a>
                </div>
            @else
                @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('final_seniority_list_us')])
            @endif

            <form method="POST" action="{{ route('dpc-proposals.seniority-list.update', $proposal) }}">
                @csrf
                @method('PUT')

                @error('entries')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                @error('seniority_list_as_on')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                @if (config('dpc.manual_entry.seniority_list'))
                    <div class="mb-2" style="max-width:18rem">
                        <label class="form-label small" for="manual-list-as-on">{{ __('dpc.seniority_list.as_on_label') }}</label>
                        <input type="date" class="form-control form-control-sm" id="manual-list-as-on" name="seniority_list_as_on" required
                               value="{{ old('seniority_list_as_on', $proposal->seniority_list_as_on?->format('Y-m-d')) }}">
                    </div>
                @endif

                {{-- Typing the list in is off by default since 2026-09-07: it comes
                     from the upload. Hidden by `dpc.manual_entry.seniority_list`
                     rather than deleted, and the route is gated on the same flag. --}}
                @unless (config('dpc.manual_entry.seniority_list'))
                    <p class="text-body-secondary small mb-0">{{ __('dpc.seniority_list.manual_entry_off') }}</p>
                @endunless

                {{-- Seed rows travel as data — see public/js/forms/dpc-seniority-list.js. --}}
                <div id="entries" data-seed="{{ json_encode($entriesData) }}"
                     @unless (config('dpc.manual_entry.seniority_list')) data-read-only @endunless></div>

                @if (config('dpc.manual_entry.seniority_list'))
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-entry">{{ __('dpc.seniority_list.add_entry') }}</button>
                @endif

                <div class="mt-3">
                    @if (config('dpc.manual_entry.seniority_list'))
                        <button type="submit" class="btn btn-success">{{ __('dpc.seniority_list.save_seniority_list') }}</button>
                    @endif
                    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>

    <template id="entry-template">
        <div class="card border-0 shadow-sm mb-2 entry-card">
            <div class="card-body row g-2">
                <div class="col-md-1"><label class="form-label">{{ __('ui.s_n') }}</label>
                    <input type="number" min="1" class="form-control form-control-sm" name="entries[__E__][seniority_number]" required></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.employee_no') }}</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][employee_number]" required></div>
                <div class="col-md-3"><label class="form-label">{{ __('ui.name') }}</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][name]" required></div>

                {{-- The number the officer signs in with, and where their OTP goes

                     (business instruction, 2026-09-07). Optional. --}}

                <div class="col-md-2"><label class="form-label">{{ __('ui.mobile') }}</label>

                    <input type="text" inputmode="numeric" maxlength="10" class="form-control form-control-sm"

                           name="entries[__E__][mobile]"></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.dob') }}</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][dob]" required></div>
                <div class="col-md-2"><label class="form-label">{{ __('ui.qualification') }}</label>
                    <input type="text" class="form-control form-control-sm" name="entries[__E__][qualification]"></div>
                <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-row">{{ __('ui.remove_2') }}</button></div>

                <div class="col-md-3"><label class="form-label">{{ __('ui.promotion_to_post') }}</label>
                    {{-- A Post is a master entity and is never typed
                         (DPC-MODULE-FIX-001). The list is the department's active
                         Department Post mappings still in force. --}}
                    <select class="form-select form-select-sm" name="entries[__E__][promotion_to_post_id]">
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($departmentPosts as $dp)
                            <option value="{{ $dp->id }}">{{ $dp->name }}</option>
                        @endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label">{{ __('ui.promotion_from_post') }}</label>
                    {{-- A Post is a master entity and is never typed
                         (DPC-MODULE-FIX-001). The list is the department's active
                         Department Post mappings still in force. --}}
                    <select class="form-select form-select-sm" name="entries[__E__][promotion_from_post_id]">
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($departmentPosts as $dp)
                            <option value="{{ $dp->id }}">{{ $dp->name }}</option>
                        @endforeach
                    </select></div>
                @if (config('dpc.reservation.category_wise'))
                <div class="col-md-3"><label class="form-label">{{ __('ui.reservation_category') }}</label>
                    <select class="form-select form-select-sm" name="entries[__E__][reservation_category_id]">
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }} — {{ $rc->name_en }}</option>@endforeach
                    </select></div>
                {{-- The **seat's** category, beside the officer's own (business
                     instruction, 2026-09-07). An SC officer selected on merit sits
                     on a General point: the box above is SC, this one is UR.
                     Optional — a department that keeps no roster leaves it blank,
                     and blank means "not recorded" rather than "same as theirs". --}}
                <div class="col-md-3"><label class="form-label">{{ __('ui.seat_category') }}</label>
                    <select class="form-select form-select-sm" name="entries[__E__][seat_reservation_category_id]">
                        <option value="">{{ __('ui.not_recorded') }}</option>
                        @foreach ($reservationCategories as $rc)<option value="{{ $rc->id }}">{{ $rc->code }} — {{ $rc->name_en }}</option>@endforeach
                    </select></div>
                @endif
                <div class="col-md-3"><label class="form-label">{{ __('dpc.seniority_list.date_of_joining') }}</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][date_of_joining_service]" required></div>
                <div class="col-md-3"><label class="form-label">{{ __('ui.date_of_posting_on_promotion_to_post') }}</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][date_of_posting_on_promotion_to_post]"></div>
                <div class="col-md-3"><label class="form-label">{{ __('ui.date_of_posting_on_promotion_from_post') }}</label>
                    <input type="date" class="form-control form-control-sm" name="entries[__E__][date_of_posting_on_promotion_from_post]"></div>
            </div>
        </div>
    </template>
    {{-- Promotion Candidate accounts for the officers on this list (item 17).

         Here rather than on a screen of its own: the person who needs it is
         standing on the list when an officer says they cannot sign in. A candidate
         account has no mobile and no e-mail, so there is no self-service reset to
         send a code on — the department reissues, which is where credential
         handover already sits. --}}
    @if ($proposal->seniorityEntries->isNotEmpty() && $proposal->status->isEditable())
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h2 class="h6 mb-1">{{ __('dpc.seniority_list.accounts.heading') }}</h2>
                <p class="small text-body-secondary mb-3">{{ __('dpc.seniority_list.accounts.reissue_lead') }}</p>

                @include('dpc-proposals.sections._candidate-accounts')

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('ui.sn') }}</th>
                                <th>{{ __('ui.name') }}</th>
                                <th>{{ __('dpc.seniority_list.accounts.employee_number') }}</th>
                                <th class="text-end">{{ __('ui.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($proposal->seniorityEntries->sortBy('seniority_number') as $entry)
                                <tr>
                                    <td>{{ $entry->seniority_number }}</td>
                                    <td>{{ $entry->name }}</td>
                                    <td>
                                        @if ($entry->employee_number)
                                            <code>{{ $entry->employee_number }}</code>
                                        @else
                                            <span class="text-body-secondary">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($entry->employee_number)
                                            <form method="POST" class="d-inline"
                                                  action="{{ route('dpc-proposals.candidates.reissue-password', $proposal) }}"
                                                  data-confirm="{{ __('dpc.seniority_list.accounts.reissue_confirm') }}">
                                                @csrf
                                                <input type="hidden" name="employee_number" value="{{ $entry->employee_number }}">
                                                <button class="btn btn-outline-secondary btn-sm">
                                                    {{ __('dpc.seniority_list.accounts.reissue') }}
                                                </button>
                                            </form>
                                        @else
                                            <span class="small text-body-secondary">{{ __('dpc.seniority_list.accounts.no_number') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/dpc-seniority-list.js') }}" defer></script>
@endpush
