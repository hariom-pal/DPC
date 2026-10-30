@extends('layouts.app')

@section('title', __($proposal->exists ? 'predpc.form.title_edit' : 'predpc.form.title_new'))

@section('content')
    {{-- Pre-DPC Proposal (PPT S7–S11), reshaped 2026-08-24 (change items 5, 8–10),
         corrected 2026-08-25.

         One exercise per proposal: one department, one DPC Year, one Promotion To
         Post — and **every post that promotion may draw on**. Schedule-IV fills most
         posts from several feeders with a share each, and one Government Order names
         them all; the reshape briefly collapsed this to a single pair, which made
         such an order impossible to record.

         Pay Scale, Class and Promotion Duration are *shown, never chosen* (item 5),
         and each is shown against the post it belongs to. A feeder's own scale and
         class sit in its row, because promotion eligibility is judged on the
         officer's current post; the Promotion To Post's sit beside it above.

         `public/js/forms/pre-dpc-exercise.js` asks `pre-dpc.post-attributes` for them
         as a post is selected, and the server derives them again on save from the
         same resolver — so what is displayed cannot disagree with what is stored. --}}
    @php
        // One definition of the required marker for the whole form, so it cannot
        // drift between the places that carry it (business instruction, 2026-09-06).
        $required = ' <span class="text-danger" aria-hidden="true">*</span>';
    @endphp

    @if ($proposal->exists)
        @include('partials.esign-issue', ['proposal' => $proposal])
    @endif
    @php
        // The Department Admin returns to the department's Pre-DPCs; an AO to their own list.
        $backTo = auth()->user()->can('viewAny', \App\Domain\PreDpc\Models\PreDpcProposal::class)
            ? route('pre-dpc.index')
            : route('department.allotments.index', ['view' => 'pre-dpc']);
        $allotted = $proposal->exists ? $proposal->currentAllotment()->with(['ao', 'ah'])->first() : null;
    @endphp
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
            <h1 class="h4 mb-0">{{ $proposal->exists ? __('predpc.summary.title_edit') : __('predpc.summary.title_new') }}</h1>
            <div class="text-muted small">{{ __('predpc.form.all_fields_required') }}</div>
        </div>
        <a href="{{ $backTo }}" class="btn btn-outline-secondary btn-sm">{{ __('predpc.form.back_to_list') }}</a>
    </div>

    @if ($proposal->exists)
        {{-- At a glance (2026-10-28): which exercise, where it stands, who has it. --}}
        <div class="summary-strip small" id="pre-dpc-summary">
            <div class="summary-item"><span class="summary-label">{{ __('predpc.summary.number') }}</span><span class="summary-value">{{ $proposal->pre_dpc_number }} · {{ __('ui.version_n', ['number' => $proposal->version]) }}</span></div>
            <div class="summary-item"><span class="summary-label">{{ __('allotments.field.dpc_year') }}</span><span class="summary-value">{{ $proposal->dpc_year }}</span></div>
            <div class="summary-item"><span class="summary-label">{{ __('ui.status') }}</span><span class="summary-value"><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></span></div>
            <div class="summary-item"><span class="summary-label">{{ __('allotments.column.ao') }}</span><span class="summary-value">{{ $allotted?->ao?->name ?? '—' }}</span></div>
            <div class="summary-item"><span class="summary-label">{{ __('allotments.column.ah') }}</span><span class="summary-value">{{ $allotted?->ah?->name ?? '—' }}</span></div>
        </div>
    @endif

    @if ($posts->isEmpty())
        <div class="alert alert-warning">
            <strong>{{ __('predpc.form.your_department_has_no_posts_registered_yet') }}</strong>
            {{ __('predpc.form.no_posts_explained') }}
        </div>
    @endif

    <form method="POST" action="{{ route('pre-dpc.update', $proposal) }}" enctype="multipart/form-data">
        @csrf
        @if ($proposal->exists) @method('PUT') @endif

        <div class="card border-0 shadow-sm mb-3 form-section">
            <div class="card-header"><strong>{{ __('predpc.form.1_the_promotion_exercise') }}</strong></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        {!! __('predpc.form.dpc_year_label') !!}
                        <input type="number" min="2000" max="{{ (int) now()->addYear()->format('Y') }}" step="1"
                               class="form-control @error('dpc_year') is-invalid @enderror"
                               id="dpc_year" name="dpc_year" readonly
                               value="{{ $proposal->dpc_year }}" required>
                        @error('dpc_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @php
                        // The post chosen on this form — what was posted back on a
                        // failed save, or what the order holds, or nothing at all on
                        // a new one. Resolved to null rather than looked up blind:
                        // a new proposal names no post, and `$derived` is keyed by
                        // the ids the order already carries.
                        $toPostId = (int) old('promotion_to_post_id', $proposal->promotion_to_post_id ?? 0);
                        $toPostAttributes = $toPostId > 0 ? ($derived[$toPostId] ?? null) : null;
                    @endphp
                    <div class="col-md-9 mb-3">
                        {!! __('predpc.form.promotion_to_post_label') !!}
                        {{-- The year and post are the Department Admin's choice (A1,
                             2026-10-26): shown, not changeable here. --}}
                        <input type="hidden" name="promotion_to_post_id" value="{{ $proposal->promotion_to_post_id }}">
                        <select class="form-select @error('promotion_to_post_id') is-invalid @enderror"
                                id="promotion_to_post_id" disabled
                                data-attributes-url="{{ route('pre-dpc.post-attributes') }}">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($posts as $post)
                                <option value="{{ $post->id }}" @selected($toPostId === $post->id)>
                                    {{ $post->name }} ({{ $post->class_level->label() }})
                                </option>
                            @endforeach
                        </select>
                        @error('promotion_to_post_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{!! __('predpc.form.hint_to_post') !!}</div>
                        <div class="form-text" id="to_post_attributes">
                            {{ __('predpc.form.to_post_attributes', [
                                'scale' => $toPostAttributes?->payScaleLabel ?? '—',
                                'class' => $toPostAttributes?->classLevel->label() ?? '—',
                            ]) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- The terms the exercise sanctions.

             Placed before the Promotion From Posts on 2026-08-25: the direct
             recruitment and promotion shares say how much of the post is filled by
             promotion at all, and the feeder rows below divide *that* share between
             the feeders. The reader needs the whole before the parts. --}}
        <div class="card border-0 shadow-sm mb-3 form-section">
            <div class="card-header"><strong>{{ __('predpc.form.2_terms_this_exercise_sanctions') }}</strong></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        {!! __('predpc.form.direct_recruitment_label') !!}
                        <input type="number" min="0" max="100" step="1" inputmode="numeric" data-whole-number
                               class="form-control @error('direct_recruitment_pct') is-invalid @enderror"
                               id="direct_recruitment_pct" name="direct_recruitment_pct" data-complement="promotion_recruitment_pct"
                               value="{{ (fn ($v) => is_numeric($v) && (float) $v == (int) $v ? (int) $v : $v)(old('direct_recruitment_pct', $proposal->direct_recruitment_pct ?? '0')) }}" required>
                        @error('direct_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        {!! __('predpc.form.promotion_label') !!}
                        <input type="number" min="0" max="100" step="1" inputmode="numeric" data-whole-number
                               class="form-control @error('promotion_recruitment_pct') is-invalid @enderror"
                               id="promotion_recruitment_pct" name="promotion_recruitment_pct" data-complement="direct_recruitment_pct"
                               value="{{ (fn ($v) => is_numeric($v) && (float) $v == (int) $v ? (int) $v : $v)(old('promotion_recruitment_pct', $proposal->promotion_recruitment_pct ?? '100')) }}" required>
                        @error('promotion_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('predpc.form.hint_shares_total') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- The Promotion From Posts.

             Plural, restored on 2026-08-25: Schedule-IV routinely fills one post
             from several — Deputy Director from Assistant Director, Senior Lecturer
             and Research Officer — and each carries its own share and its own
             qualifying service.

             **Each row shows that post's own Pay Scale and Class**, because that is
             what promotion eligibility is judged on: the officer's current post. --}}
        <div class="card border-0 shadow-sm mb-3 form-section">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong>{{ __('predpc.form.3_promotion_from_posts') }}</strong>
                    <div class="small text-body-secondary">{{ __('predpc.form.sources_lead') }}</div>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" id="add-promotion-source">
                    {{ __('predpc.form.add_source') }}
                </button>
            </div>
            <div class="card-body">
                @error('promotion_sources')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                @php
                    // The rows to render: what was posted back on a failed save, or
                    // what the order already holds, or one empty row to start from.
                    $sourceRows = collect(old('promotion_sources', $proposal->promotionSources->map(fn ($source) => [
                        'promotion_from_post_id' => $source->promotion_from_post_id,
                        'promotion_percentage' => $source->promotion_percentage,
                        'min_experience_years' => $source->min_experience_years,
                        'experience_unit' => $source->experience_unit->value,
                        // Plural since item 11 (2026-08-26): a feeder may accept
                        // any of several qualifications.
                        'qualification_ids' => $source->qualifications->pluck('id')->all(),
                    ])->all()));

                    if ($sourceRows->isEmpty()) {
                        $sourceRows = collect([[]]);
                    }
                @endphp

                <div class="table-responsive">
                    {{-- The four strings the script writes into the Pay Scale & Class
                         cell come from the catalogue and not from the script, so the
                         cell reads in the user's language like the rest of the row.
                         The script has no catalogue of its own. --}}
                    <table class="table table-sm align-middle" id="promotion-sources-table" style="min-width:60rem"
                           data-label-pay-scale="{{ __('ui.pay_scale') }}"
                           data-label-class="{{ __('ui.class') }}"
                           data-text-empty="{{ __('predpc.form.select_a_post_first') }}"
                           data-text-not-live="{{ __('predpc.form.post_not_live') }}"
                           data-text-unavailable="{{ __('predpc.form.attributes_unavailable') }}">
                        <thead class="table-light">
                            <tr>
                                {{-- Every column but the derived one is required
                                     (business instruction, 2026-09-06: *"all fields
                                     are mandatory in Pre-DPC, put an asterisk on
                                     all fields"*). Pay Scale & Class carries none
                                     because nobody enters it — it is read off the
                                     post. --}}
                                <th style="width:26%">{{ __('predpc.form.promotion_from_post') }}{!! $required !!}</th>
                                <th style="width:16%">{{ __('predpc.form.its_pay_scale_and_class') }}</th>
                                <th style="width:11%">{{ __('predpc.form.share') }}{!! $required !!}</th>
                                <th style="width:19%">{{ __('predpc.form.min_experience') }}{!! $required !!}</th>
                                <th style="width:22%">{{ __('predpc.form.min_qualification') }}{!! $required !!}</th>
                                <th style="width:6%"></th>
                            </tr>
                        </thead>
                        <tbody data-next-index="{{ $sourceRows->count() }}">
                            @foreach ($sourceRows as $index => $row)
                                @include('pre-dpc.partials.promotion-source-row', [
                                    'index' => $index,
                                    'row' => $row,
                                    'posts' => $posts,
                                    'qualifications' => $qualifications,
                                    'experienceUnits' => $experienceUnits,
                                    'derived' => $derived,
                                ])
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="small text-body-secondary mb-1">{{ __('predpc.form.sources_share_note') }}</p>

                {{-- The running total, and the reason Save is disabled when it is
                     not 100 (business instruction, 2026-09-06: *"share should
                     always be calculated to 100; if it is under 100 do not let it
                     submit"*).

                     `SavePreDpcProposalRequest::assertSourceSharesTotalOneHundred()`
                     refuses the same thing on the server and is the check that
                     decides; this one saves the round trip and, more usefully, tells
                     the officer *while they are typing* which way they are out. --}}
                <div id="share-total" class="small"
                     data-text-ok="{{ __('predpc.form.share_total_ok') }}"
                     data-text-short="{{ __('predpc.form.share_total_short') }}"
                     data-text-over="{{ __('predpc.form.share_total_over') }}"></div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3 form-section">
            <div class="card-header"><strong>{{ __('predpc.form.4_government_order') }}</strong></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="government_order_number">{{ __('predpc.form.government_order_number') }}{!! $required !!}</label>
                        <input type="text" maxlength="255" class="form-control @error('government_order_number') is-invalid @enderror"
                               id="government_order_number" name="government_order_number"
                               value="{{ old('government_order_number', $proposal->government_order_number) }}">
                        @error('government_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">{{ __('predpc.form.publish_date') }}{!! $required !!}</label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                               id="effective_from" name="effective_from"
                               value="{{ old('effective_from', $proposal->effective_from?->format('Y-m-d')) }}">
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- The Bharti Niyam itself, as a PDF (2026-10-30). --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="rules_document">{{ __('predpc.form.rules_document') }}</label>
                        <input type="file" accept="application/pdf,.pdf" class="form-control @error('rules_document') is-invalid @enderror"
                               id="rules_document" name="rules_document">
                        @error('rules_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            @if ($proposal->hasRulesDocument())
                                {{ __('predpc.form.rules_document_current') }}
                                <a href="{{ route('pre-dpc.rules-document', $proposal) }}">{{ $proposal->rules_document_name }}</a>.
                            @endif
                            {{ __('predpc.form.rules_document_hint') }}
                        </div>
                    </div>
                </div>
                <p class="text-muted small mb-0">
                    {{ __('predpc.form.both_are_required_before_the_proposal_can_be_sub') }}
                </p>
            </div>
        </div>

        {{-- The preparer's declaration (MoM of 2026-10-29), made with every save
             (2026-10-30); the AO makes it again on submitting. --}}
        <div class="mb-3" id="pre-dpc-form-declaration">@include('partials.declaration', ['key' => 'pre_dpc_submit'])</div>

        {{-- `data-no-posts` tells pre-dpc-exercise.js to leave this button alone.
             It governs Save on the share total, and would otherwise *enable* a
             button that is disabled because the department has no posts at all. --}}
        <div class="form-actions-sticky">
            <button type="submit" class="btn btn-success" id="save-pre-dpc"
                    @disabled($posts->isEmpty()) @if ($posts->isEmpty()) data-no-posts @endif>{{ __('predpc.form.save_draft') }}</button>
            <a href="{{ $backTo }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
        </div>
    </form>

    @push('scripts')
        <script src="@versioned('js/forms/pre-dpc-exercise.js')" defer></script>
    @endpush
    {{-- The blank row the Add button clones. __INDEX__ is replaced on insert. --}}
    <template id="promotion-source-row-template">
        <table><tbody>
            @include('pre-dpc.partials.promotion-source-row', [
                'index' => '__INDEX__',
                'row' => [],
                'posts' => $posts,
                'qualifications' => $qualifications,
                'experienceUnits' => $experienceUnits,
                'derived' => $derived,
            ])
        </tbody></table>
    </template>
@endsection
