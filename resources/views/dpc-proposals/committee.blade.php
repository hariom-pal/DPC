@extends('layouts.app')

@section('title', __('ui.dpc_committee'))

@php
    $serving = $proposal->committeeHistory->where('active_flag', '!==', null)->values();
    $replaced = $proposal->committeeHistory->whereNull('active_flag')->values();

    // Only the members the officer may edit travel to the form. The CGPSC
    // Chairman's seat is placed server-side on every save (item 9), so it is shown
    // beside the form rather than in it.
    $editable = $serving->where('capacity', \App\Domain\Dpc\Enums\CommitteeCapacity::Member)->values();

    /* The committee row stores the designation *name* (the snapshot the record has
       always held), while the form posts the master id. This maps one back to the
       other so an existing committee reopens with its dropdowns already set; a name
       no longer in the master simply reopens blank, which is the honest state. */
    $designationIdByName = $designations->pluck('id', 'name')->all();

    $membersData = old('members', $editable->map(fn ($m) => [
        'user_id' => $m->user_id,
        'name' => $m->name,
        'designation' => $m->designation,
        'designation_id' => $designationIdByName[$m->designation] ?? null,
        'mobile' => $m->mobile,
        'email' => $m->email,
        'capacity' => $m->capacity->value,
        'is_sc' => (bool) $m->is_sc,
        'is_st' => (bool) $m->is_st,
        'is_obc' => (bool) $m->is_obc,
        'is_female' => (bool) $m->is_female,
        'is_bhar_sadhak_sachiv' => (bool) $m->is_bhar_sadhak_sachiv,
        'is_vibhag_sachiv' => (bool) $m->is_vibhag_sachiv,
    ])->all());

    $dpcChairman = $serving->firstWhere('is_dpc_chairman', '!==', null);
@endphp

@section('content')
    {{-- DPC Committee (PPT S15), rewritten 2026-08-26 for change items 5 to 9.

         There is no Commission Members master any more. A member is either an
         account found by e-mail or mobile, or a person recorded here — and the six
         composition attributes belong to *this* membership rather than to the
         person, because committee membership is valid only for this DPC. --}}
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('ui.dpc_committee') }}</h1>
            <p class="text-muted small mb-3">{{ $proposal->citation() }}</p>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>{{ __('dpc.committee.what_this_committee_must_satisfy') }}</strong></div>
                <div class="card-body">
                    <ul class="mb-0 small">
                        {!! __('dpc.committee.size_rule', ['size' => e($rules->sizeLabel())]) !!}
                        {!! __('dpc.committee.chairman_required_rule') !!}
                        @if ($rules->requiresReservedCategory)
                            <li>{!! __('dpc.committee.must_represent', ['categories' => e($rules->reservedCategoryLabel())]) !!}</li>
                        @endif
                        @if ($rules->requiredCategories !== [])
                            <li>{!! __('dpc.committee.must_represent', ['categories' => e(implode(', ', $rules->requiredCategoryLabels()))]) !!}</li>
                        @endif
                        @unless ($rules->hasCompositionRequirements())
                            <li class="text-body-secondary">{{ __('dpc.committee.no_category_is_required_by_configuration_so_only') }}</li>
                        @endunless
                    </ul>
                </div>
            </div>

            {{-- Item 9. The seat exists whether or not the officer can see it, so
                 showing it is the difference between a committee they understand
                 and one that grows a member when they press Save. --}}
            @if ($chairman === null)
                <div class="alert alert-danger">
                    {!! __('dpc.committee.chairman_none_recorded') !!}
                </div>
            @else
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <span class="badge text-bg-secondary">{{ __('dpc.committee.chairman_seat') }}</span>
                            <strong class="ms-2">{{ $chairman->name }}</strong>
                            @if ($chairman->designation)
                                <span class="text-body-secondary small">— {{ $chairman->designation }}</span>
                            @endif
                        </div>
                        @if ($dpcChairman?->isCgpscChairman())
                            <span class="badge text-bg-primary">{{ __('dpc.committee.dpc_chairman_badge') }}</span>
                        @endif
                    </div>
                </div>
            @endif

            <p class="small text-body-secondary mb-2">
                @if ($dpcChairman === null)
                    {{ __('dpc.committee.dpc_chairman_none') }}
                @else
                    <span class="badge text-bg-primary">{{ __('dpc.committee.dpc_chairman_badge') }}</span>
                    <strong>{{ $dpcChairman->name }}</strong>
                @endif
            </p>

            {{-- The same requirements as the card above, but as a live checklist
                 that ticks as the officer works (business instruction, 2026-09-06:
                 *"saare combination hai ya nahi hai"* must be visible while
                 composing, not only on Save).

                 Rendered server-side from the same `$rules` the compliance service
                 reads, and re-evaluated in the browser by dpc-committee.js on every
                 change. It is a guide, never the decision: `SaveDpcCommitteeRequest`
                 refuses a non-compliant committee whatever this panel says. --}}
            @if ($rules->hasCompositionRequirements())
                <div class="card border-0 shadow-sm mb-3" id="committee-requirements"
                     data-min="{{ $rules->minMembers }}"
                     data-max="{{ $rules->maxMembers }}"
                     data-text-met="{{ __('dpc.committee.requirement_met') }}"
                     data-text-pending="{{ __('dpc.committee.requirement_pending') }}"
                     data-text-all-met="{{ __('dpc.committee.requirements_all_met') }}">
                    <div class="card-header bg-white py-2">
                        <strong>{{ __('dpc.committee.requirements_heading') }}</strong>
                    </div>
                    <div class="card-body py-2">
                        <ul class="list-unstyled mb-0 small" id="requirement-list">
                            <li class="requirement d-flex align-items-center gap-2 py-1"
                                data-requirement="size">
                                <span class="requirement-state badge text-bg-secondary"></span>
                                <span>{{ __('dpc.committee.requirement_size', ['min' => $rules->minMembers, 'max' => $rules->maxMembers]) }}</span>
                            </li>
                            @if ($rules->requiresReservedCategory)
                                <li class="requirement d-flex align-items-center gap-2 py-1"
                                    data-requirement="reserved"
                                    data-columns="{{ implode(',', array_map(fn ($c) => $c->column(), \App\Domain\Committee\Enums\MemberCategory::socialCategories())) }}">
                                    <span class="requirement-state badge text-bg-secondary"></span>
                                    <span>{{ __('dpc.committee.requirement_reserved', ['categories' => $rules->reservedCategoryLabel()]) }}</span>
                                </li>
                            @endif
                            @foreach ($rules->requiredCategories as $required)
                                <li class="requirement d-flex align-items-center gap-2 py-1"
                                    data-requirement="attribute"
                                    data-columns="{{ $required->column() }}">
                                    <span class="requirement-state badge text-bg-secondary"></span>
                                    <span>{{ $required->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="text-success small mt-2 d-none" id="requirements-all-met"></div>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('dpc-proposals.committee.update', $proposal) }}">
                @csrf
                @method('PUT')

                {{-- Seed and endpoint travel as data attributes, never as
                     interpolated script — see public/js/forms/dpc-committee.js
                     and TD-29. --}}
                <div id="members"
                     {{-- `array_values`, because a JSON **object** here is fatal:
                          dpc-committee.js reads this with `forEach`. Old input from
                          a refused save can arrive with gaps in its keys — the
                          officer removed a card before saving — and PHP encodes a
                          non-sequential array as an object. The script now keeps the
                          indices contiguous as well; this is the belt to that
                          braces, and it is the half that cannot be undone by a
                          stale page. --}}
                     data-seed="{{ json_encode(array_values($membersData)) }}"
                     data-search-url="{{ route('dpc-proposals.committee.search', $proposal) }}"
                     data-text-exists="{{ __('dpc.committee.existing_user_found') }}"
                     data-text-use-existing="{{ __('dpc.committee.use_existing_user') }}"
                     data-text-no-match="{{ __('dpc.committee.search_no_match') }}"
                     data-text-member="{{ __('dpc.committee.member_number', ['n' => ':n']) }}"
                     data-text-incomplete="{{ __('dpc.committee.member_incomplete') }}"
                     data-text-unreserved="{{ __('dpc.committee.unreserved_badge') }}"
                     data-text-duplicate-in-form="{{ __('dpc.committee.duplicate_in_form', ['member' => ':member']) }}"
                     data-text-name-required="{{ __('dpc.committee.validation_name_required') }}"
                     data-text-designation-required="{{ __('dpc.committee.validation_designation_required') }}"
                     data-text-mobile-required="{{ __('dpc.committee.validation_mobile_required') }}"
                     data-text-mobile-shape="{{ __('dpc.committee.validation.mobile_shape') }}"
                     data-text-email-domain="{{ __('dpc.committee.validation.email_domain') }}"></div>

                {{-- Disabled until the open row is complete: a blank row added on
                     top of a blank row is how this screen came to feel like the same
                     member being entered over and over. dpc-committee.js enables it. --}}
                <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-member">{{ __('dpc.committee.add_member') }}</button>
                <div class="form-text" id="add-member-hint"></div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success">{{ __('dpc.committee.save_committee') }}</button>
                    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </div>
            </form>

            {{-- Item 9e. Replaced members stay on the record with who replaced them
                 and why, so a proceeding that cites one stays truthful. --}}
            @if ($replaced->isNotEmpty())
                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-header bg-white"><strong>{{ __('dpc.committee.replaced_badge') }}</strong></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($replaced as $member)
                            <li class="list-group-item small">
                                <span class="text-decoration-line-through">{{ $member->name }}</span>
                                @if ($member->attributeLabel())
                                    <span class="badge text-bg-light">{{ $member->attributeLabel() }}</span>
                                @endif
                                <div class="text-body-secondary">
                                    {{ __('dpc.committee.replaced_on', [
                                        'date' => $member->replaced_at?->format('d-m-Y') ?? '—',
                                        'actor' => $member->replacer?->name ?? '—',
                                        'reason' => $member->replacement_reason ?? '—',
                                    ]) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <template id="member-template">
        <div class="card border-0 shadow-sm mb-3 member-card">
            {{-- Reworked 2026-09-06. A card is in one of two states and never both:
                 **locked**, showing one summary line, or **open**, showing the form
                 below. Only one card is ever open, and "+ Add Member" stays disabled
                 while one is — so the officer sees a numbered list of the members
                 they have entered plus at most one they are entering, rather than a
                 column of identical blank forms.

                 Nothing is removed by locking. Every input stays in the DOM and
                 still posts; the body is hidden, not detached. --}}
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center gap-2 member-summary d-none">
                <div class="small">
                    <span class="badge text-bg-secondary member-index"></span>
                    <strong class="ms-1 member-summary-name"></strong>
                    <span class="text-body-secondary member-summary-designation"></span>
                    <span class="text-body-secondary member-summary-contact"></span>
                    <span class="member-summary-badges"></span>
                </div>
                <div class="text-nowrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm member-edit">{{ __('dpc.committee.member_edit') }}</button>
                    <button type="button" class="btn btn-outline-danger btn-sm remove-member">{{ __('ui.remove_2') }}</button>
                </div>
            </div>

            <div class="card-body member-body">
                <div class="mb-2"><span class="badge text-bg-primary member-index"></span></div>

                <input type="hidden" class="member-user-id" name="members[__M__][user_id]" value="">
                <input type="hidden" name="members[__M__][capacity]" value="member">

                {{-- Item 6, option 1: find somebody who is already in the portal. --}}
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-md-6">
                        <label class="form-label small">{{ __('dpc.committee.search_existing_user') }}</label>
                        <input type="search" class="form-control form-control-sm member-search"
                               placeholder="{{ __('dpc.committee.search_placeholder') }}"
                               aria-label="{{ __('dpc.committee.search_existing_user') }}">
                        <div class="form-text">{{ __('dpc.committee.search_hint') }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="member-search-results small"></div>
                    </div>
                </div>

                {{-- Item 6, option 2: record somebody new. The duplicate check runs
                     as the e-mail or mobile is typed. --}}
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('ui.name') }}</label>
                        <input type="text" class="form-control form-control-sm member-name" name="members[__M__][name]" autocomplete="off">
                        <div class="invalid-feedback member-name-error"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="member-__M__-designation">{{ __('ui.designation') }}</label>
                        {{-- Chosen from the Designation master, never typed
                             (DPC-MODULE-FIX-001, second pass). The list is long
                             enough to want the type-to-filter box that
                             public/js/searchable-select.js adds; without that
                             script it stays an ordinary dropdown that still
                             submits. --}}
                        <select class="form-select form-select-sm member-designation"
                                id="member-__M__-designation"
                                name="members[__M__][designation_id]"
                                data-searchable
                                data-searchable-placeholder="{{ __('ui.search') }}">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($designations as $designation)
                                <option value="{{ $designation->id }}">{{ $designation->name }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback member-designation-error"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('ui.mobile') }}</label>
                        {{-- Ten digits, digits only; and a gov.in address. Checked
                             here as the officer types **and** by
                             `SaveDpcCommitteeRequest`, which is the check that
                             decides — the browser's is a courtesy (business
                             instruction, 2026-09-06). --}}
                        <input type="text" class="form-control form-control-sm member-mobile"
                               name="members[__M__][mobile]"
                               inputmode="numeric" maxlength="10" autocomplete="off">
                        <div class="invalid-feedback member-mobile-error"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">{{ __('ui.email') }}</label>
                        <input type="email" class="form-control form-control-sm member-email"
                               name="members[__M__][email]" autocomplete="off">
                        <div class="invalid-feedback member-email-error"></div>
                    </div>
                </div>

                <div class="member-duplicate alert alert-warning py-1 px-2 small mt-2 mb-0 d-none"></div>

                {{-- Business clarification of 2026-09-05. Two groups, because the
                     two behave differently: a member holds at most one social
                     category, and any combination of the rest.

                     The social category is still three checkboxes rather than a
                     radio group, so the posted shape stays `is_sc` / `is_st` /
                     `is_obc` — the columns, the compliance service and every
                     existing committee payload speak that. `dpc-committee.js`
                     makes them behave as one choice by clearing the siblings, and
                     `CommitteeComplianceService` refuses a payload claiming two
                     regardless of what the browser did.

                     There is deliberately no UR box: unticking all three *is* UR,
                     and the badge below says so as the officer works. --}}
                <fieldset class="mt-3 member-social">
                    <legend class="form-label small mb-1">
                        {{ __('dpc.committee.social_category_heading') }}
                        <span class="text-body-secondary fw-normal">— {{ __('dpc.committee.social_category_hint') }}</span>
                    </legend>
                    <div class="d-flex flex-wrap gap-3 align-items-center">
                        @foreach ($socialCategories as $attribute)
                            <div class="form-check">
                                <input class="form-check-input member-social-input" type="checkbox" value="1"
                                       id="member-__M__-{{ $attribute->value }}"
                                       name="members[__M__][{{ $attribute->column() }}]">
                                <label class="form-check-label small" for="member-__M__-{{ $attribute->value }}">
                                    {{ $attribute->label() }}
                                </label>
                            </div>
                        @endforeach
                        <span class="badge text-bg-light member-social-none">{{ __('dpc.committee.social_category_none') }}</span>
                    </div>
                </fieldset>

                <fieldset class="mt-3">
                    <legend class="form-label small mb-1">
                        {{ __('dpc.committee.additional_attributes_heading') }}
                        <span class="text-body-secondary fw-normal">— {{ __('dpc.committee.additional_attributes_hint') }}</span>
                    </legend>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($additionalAttributes as $attribute)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="member-__M__-{{ $attribute->value }}"
                                       name="members[__M__][{{ $attribute->column() }}]">
                                <label class="form-check-label small" for="member-__M__-{{ $attribute->value }}">
                                    {{ $attribute->label() }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-member">{{ __('ui.remove_2') }}</button>
                    <button type="button" class="btn btn-primary btn-sm member-done">{{ __('dpc.committee.member_done') }}</button>
                </div>
            </div>
        </div>
    </template>
@endsection

@push('scripts')
    <script src="@versioned('js/forms/dpc-committee.js')" defer></script>
    <script src="@versioned('js/forms/committee-social-category.js')" defer></script>
@endpush
