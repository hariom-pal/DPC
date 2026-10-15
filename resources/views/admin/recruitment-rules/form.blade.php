@extends('layouts.admin')

@section('title', __($rule->exists ? 'admin.recruitment_rules.form.title_edit' : 'admin.recruitment_rules.form.title_new'))

@section('content')
    <h1 class="h4 mb-1">{{ $rule->exists ? 'Edit Recruitment Rule (v'.$rule->version.')' : 'New Recruitment Rule' }}</h1>
    <p class="text-body-secondary">
        {{ __('admin.recruitment_rules.form.a_recruitment_rule_records_for_one_post_how_many') }}
    </p>

    @php
        // Rows come from three places, in this order of precedence:
        //   1. old() — a submission that failed validation, so the user's typing
        //      is never lost;
        //   2. the saved feeders, when editing;
        //   3. nothing, for a new rule — the user presses Add Promotion From Post.
        $rows = collect(old('promotionSources', $rule->promotionSources?->map(fn ($f) => [
            'promotion_from_post_id' => $f->promotion_from_post_id,
            'promotion_percentage' => $f->promotion_percentage,
            'min_experience_years' => $f->min_experience_years,
            'min_experience_unit' => $f->min_experience_unit->value,
            // Plural since item 11 (2026-08-26): a Schedule-IV entry accepts any
            // of a list of degrees.
            'qualification_ids' => $f->qualifications->pluck('id')->all(),
        ])->all() ?? []));

        // Keys are kept exactly as posted. Removing the third of four rows posts
        // 0, 1, 3 — reindexing that to 0, 1, 2 would show the error reported for
        // row 3 against row 2. The Add button continues from the highest key.
        $nextIndex = $rows->keys()->filter(fn ($k) => is_numeric($k))->max();
        $nextIndex = $nextIndex === null ? 0 : (int) $nextIndex + 1;
    @endphp

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="rule-form"
                  action="{{ $rule->exists ? route('admin.recruitment-rules.update', $rule) : route('admin.recruitment-rules.store') }}">
                @csrf
                @if ($rule->exists) @method('PUT') @endif

                {{-- ============================================================ --}}
                {{-- Step 1 — the post the rule is for                            --}}
                {{-- ============================================================ --}}
                <h2 class="h6 text-uppercase text-body-secondary border-bottom pb-2 mb-3">
                    {{ __('admin.recruitment_rules.form.step_1_the_post_this_rule_is_for') }}
                </h2>

                <div class="row">
                    <div class="col-md-5 mb-3">
                        {!! __('admin.recruitment_rules.form.target_post_label') !!}
                        <select class="form-select @error('post_id') is-invalid @enderror"
                                id="post_id" name="post_id" required
                                data-searchable data-searchable-placeholder="{{ __('ui.search_posts') }}">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($posts as $post)
                                <option value="{{ $post->id }}"
                                        data-department="{{ $post->department_id }}"
                                        @selected(old('post_id', $rule->post_id) == $post->id)>
                                    {{ $post->name }} ({{ $post->class_level->label() }}) — {{ $post->department?->code }}
                                </option>
                            @endforeach
                        </select>
                        @error('post_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">{!! __('admin.recruitment_rules.form.hint_target_post') !!}</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="government_order_number">{!! __('admin.recruitment_rules.form.government_order_no') !!}</label>
                        <input type="text" class="form-control @error('government_order_number') is-invalid @enderror"
                               id="government_order_number" name="government_order_number"
                               value="{{ old('government_order_number', $rule->government_order_number) }}" required>
                        @error('government_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.recruitment_rules.form.hint_notification') }}</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        {!! __('admin.recruitment_rules.form.status_label') !!}
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $rule->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">{!! __('admin.recruitment_rules.form.hint_status') !!}</div>
                    </div>
                </div>

                {{-- ============================================================ --}}
                {{-- Step 2 — how the posts are filled                            --}}
                {{-- ============================================================ --}}
                <h2 class="h6 text-uppercase text-body-secondary border-bottom pb-2 mb-3 mt-4">
                    {{ __('admin.recruitment_rules.form.step_2_how_the_vacancies_are_filled') }}
                </h2>

                {{-- The recruitment split, given its own row.
                     ------------------------------------------------------------
                     These two are the only fields on the screen that constrain
                     each other, so they are shown together with their running
                     total rather than mixed in among the dates — a reader can
                     see at a glance that the post is fully accounted for. The
                     columns are wide enough for "100.00" plus the spinner,
                     which the previous col-md-3 was not. --}}
                <div class="row align-items-start recruitment-split">
                    <div class="col-md-4 col-lg-3 mb-3">
                        <label class="form-label" for="direct_recruitment_pct">
                            {!! __('admin.recruitment_rules.form.direct_recruitment_label') !!}
                        </label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100"
                                   class="form-control @error('direct_recruitment_pct') is-invalid @enderror"
                                   id="direct_recruitment_pct" name="direct_recruitment_pct"
                                   value="{{ old('direct_recruitment_pct', $rule->direct_recruitment_pct ?? 0) }}" required>
                            <span class="input-group-text">%</span>
                            @error('direct_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-text">{{ __('admin.recruitment_rules.form.hint_direct_share') }}</div>
                    </div>
                    <div class="col-md-4 col-lg-3 mb-3">
                        <label class="form-label" for="promotion_recruitment_pct">
                            {!! __('admin.recruitment_rules.form.promotion_label') !!}
                        </label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100"
                                   class="form-control @error('promotion_recruitment_pct') is-invalid @enderror"
                                   id="promotion_recruitment_pct" name="promotion_recruitment_pct"
                                   value="{{ old('promotion_recruitment_pct', $rule->promotion_recruitment_pct ?? 0) }}" required>
                            <span class="input-group-text">%</span>
                            @error('promotion_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-text">{{ __('admin.recruitment_rules.form.hint_promotion_share') }}</div>
                    </div>
                    <div class="col-md-4 col-lg-6 mb-3">
                        <span class="form-label d-block">{{ __('admin.recruitment_rules.form.dr_pr') }}</span>
                        <div class="split-total-box border rounded px-3 py-2">
                            <span class="share-total" id="split-total">0%</span>
                            <span id="split-total-mark" aria-hidden="true"></span>
                            <span class="small text-body-secondary ms-2" id="split-total-hint"></span>
                        </div>
                        <div class="form-text">
                            {!! __('admin.recruitment_rules.form.hint_split_total') !!}
                        </div>
                    </div>
                </div>

                {{-- The sum error is reported against its own key, not against
                     either field: neither number is wrong on its own, it is the
                     pair that does not add up, and blaming one of them would
                     tell the user to change the wrong one. --}}
                @error('recruitment_split')
                    <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                @enderror

                <div class="row">
                    <div class="col-md-3 mb-3">
                        {!! __('admin.recruitment_rules.form.effective_from_label') !!}
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                               id="effective_from" name="effective_from"
                               value="{{ old('effective_from', $rule->effective_from?->format('Y-m-d')) }}" required>
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">{{ __('ui.effective_to') }}</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror"
                               id="effective_to" name="effective_to"
                               value="{{ old('effective_to', $rule->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.recruitment_rules.form.hint_effective_to') }}</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="go_document">{{ __('admin.recruitment_rules.form.government_order_pdf') }}</label>
                        <input type="file" accept="application/pdf" class="form-control @error('go_document') is-invalid @enderror"
                               id="go_document" name="go_document">
                        @if ($rule->go_document_path)
                            <div class="form-text">{!! __('admin.recruitment_rules.form.current_go_document', [
                                'link' => '<a href="'.e(route('admin.recruitment-rules.download', $rule)).'">'.e(__('ui.go_pdf')).'</a>',
                            ]) !!}</div>
                        @endif
                        @error('go_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                {{-- ============================================================ --}}
                {{-- Step 3 — the promotion from posts                                   --}}
                {{-- ============================================================ --}}
                <div class="d-flex justify-content-between align-items-end border-bottom pb-2 mb-3 mt-4">
                    <div>
                        <h2 class="h6 text-uppercase text-body-secondary mb-1">{{ __('admin.recruitment_rules.form.step_3_promotion_from_posts_schedule_iv') }}</h2>
                        <p class="text-body-secondary small mb-0">
                            {!! __('admin.recruitment_rules.form.step_3_lead') !!}
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" id="add-promotion-source">
                        {{ __('admin.recruitment_rules.form.add_promotion_from_post') }}
                    </button>
                </div>

                @error('promotionSources')
                    <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                @enderror

                <div class="promotion-source-grid">
                    {{-- Fixed layout, with the column widths declared once in the
                         colgroup. Under the default auto layout a long header note
                         either stretches the table past the page (clipping Action)
                         or overflows its own cell into the next one; fixed layout
                         makes every column honour its width and wrap inside it. --}}
                    <table class="table table-sm align-middle" id="promotion-sources-table" style="table-layout:fixed; min-width:56rem">
                        <colgroup>
                            <col style="width:30%">
                            <col style="width:15%">
                            <col style="width:18%">
                            <col style="width:25%">
                            <col style="width:12%">
                        </colgroup>
                        <thead class="table-light">
                            <tr>
                                <th>
                                    {!! __('admin.recruitment_rules.form.promotion_from_post_label') !!}
                                    <span class="th-note">{{ __('admin.recruitment_rules.form.chosen_from_the_department_post_master') }}</span>
                                </th>
                                <th>
                                    {!! __('admin.recruitment_rules.form.promotion_share_label') !!}
                                    <span class="th-note">{{ __('admin.recruitment_rules.form.percentage_of_promotion_quota_allocated_to_this') }}</span>
                                </th>
                                {{ __('admin.recruitment_rules.form.minimum_experience_label') }}
                                    <span class="th-note">{{ __('admin.recruitment_rules.form.value_and_unit_e_g_5_years') }}</span>
                                </th>
                                {{ __('admin.recruitment_rules.form.minimum_qualification_label') }}
                                    <span class="th-note">{{ __('admin.recruitment_rules.form.from_the_qualification_master') }}</span>
                                </th>
                                <th class="text-end">{{ __('ui.action') }}</th>
                            </tr>
                        </thead>
                        {{-- The next free row index travels as data — see
                             public/js/forms/recruitment-rule.js and TD-29. --}}
                        <tbody data-next-index="{{ $nextIndex }}">
                            @foreach ($rows as $i => $row)
                                @include('admin.recruitment-rules.partials.promotion-source-row', [
                                    'index' => $i, 'row' => $row,
                                    'posts' => $posts, 'qualifications' => $qualifications, 'units' => $units,
                                ])
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr id="promotion-sources-empty" @class(['d-none' => $rows->isNotEmpty()])>
                                <td colspan="5" class="text-center text-body-secondary py-4">
                                    {!! __('admin.recruitment_rules.form.no_promotion_from_post_yet') !!}
                                </td>
                            </tr>
                            <tr class="table-light">
                                <th class="text-end">{{ __('ui.total') }}</th>
                                <th>
                                    <span class="share-total" id="share-total">0%</span>
                                    <span id="share-total-mark" aria-hidden="true"></span>
                                </th>
                                <th colspan="3" class="fw-normal small text-body-secondary" id="share-total-hint"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="alert alert-light border small d-flex gap-2 mt-1">
                    <span aria-hidden="true">ℹ</span>
                    <span>
                        {!! __('admin.recruitment_rules.form.promotion_share_explained') !!}
                    </span>
                </div>

                @if ($qualifications->isEmpty())
                    <div class="alert alert-warning py-2 small">
                        {!! __('admin.recruitment_rules.form.no_qualification_master', [
                            'link' => '<a href="'.e(route('admin.qualifications.index')).'">'
                                .e(__('admin.recruitment_rules.form.administration_qualifications')).'</a>',
                        ]) !!}
                    </div>
                @endif

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                    <a href="{{ route('admin.recruitment-rules.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>

    {{-- The blank row the Add button clones. __INDEX__ is replaced on insert. --}}
    <template id="promotion-source-row-template">
        @include('admin.recruitment-rules.partials.promotion-source-row', [
            'index' => '__INDEX__', 'row' => null,
            'posts' => $posts, 'qualifications' => $qualifications, 'units' => $units,
        ])
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/forms/recruitment-rule.js') }}" defer></script>
@endpush
