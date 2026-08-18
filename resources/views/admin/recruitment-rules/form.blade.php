@extends('layouts.admin')

@section('title', $rule->exists ? 'Edit Recruitment Rule' : 'New Recruitment Rule')

@section('content')
    <h1 class="h4 mb-1">{{ $rule->exists ? 'Edit Recruitment Rule (v'.$rule->version.')' : 'New Recruitment Rule' }}</h1>
    <p class="text-body-secondary">
        A recruitment rule records, for one post, how many of its vacancies are filled by promotion and from
        which post(s) those officers are promoted. Complete the three steps in order.
    </p>

    @php
        // Rows come from three places, in this order of precedence:
        //   1. old() — a submission that failed validation, so the user's typing
        //      is never lost;
        //   2. the saved feeders, when editing;
        //   3. nothing, for a new rule — the user presses Add Feeder Cadre.
        $rows = collect(old('feeders', $rule->feeders?->map(fn ($f) => [
            'feeder_cadre_id' => $f->feeder_cadre_id,
            'promotion_percentage' => $f->promotion_percentage,
            'min_experience_years' => $f->min_experience_years,
            'min_experience_unit' => $f->min_experience_unit->value,
            'qualification_id' => $f->qualification_id,
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
                    Step 1 · The post this rule is for
                </h2>

                <div class="row">
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="cadre_id">Target Cadre <span class="text-danger">*</span></label>
                        <select class="form-select @error('cadre_id') is-invalid @enderror"
                                id="cadre_id" name="cadre_id" required
                                data-searchable data-searchable-placeholder="Search cadres…">
                            <option value="">— Select —</option>
                            @foreach ($cadres as $cadre)
                                <option value="{{ $cadre->id }}"
                                        data-department="{{ $cadre->department_id }}"
                                        @selected(old('cadre_id', $rule->cadre_id) == $cadre->id)>
                                    {{ $cadre->name }} ({{ $cadre->class_level->label() }}) — {{ $cadre->department?->code }}
                                </option>
                            @endforeach
                        </select>
                        @error('cadre_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">The post officers are promoted <strong>into</strong>.</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="government_order_number">Government Order No. <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('government_order_number') is-invalid @enderror"
                               id="government_order_number" name="government_order_number"
                               value="{{ old('government_order_number', $rule->government_order_number) }}" required>
                        @error('government_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">The notification this rule is taken from.</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $rule->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Only a <strong>Draft</strong> can be edited later.</div>
                    </div>
                </div>

                {{-- ============================================================ --}}
                {{-- Step 2 — how the posts are filled                            --}}
                {{-- ============================================================ --}}
                <h2 class="h6 text-uppercase text-body-secondary border-bottom pb-2 mb-3 mt-4">
                    Step 2 · How the vacancies are filled
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
                            Direct Recruitment — DR (%) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100"
                                   class="form-control @error('direct_recruitment_pct') is-invalid @enderror"
                                   id="direct_recruitment_pct" name="direct_recruitment_pct"
                                   value="{{ old('direct_recruitment_pct', $rule->direct_recruitment_pct ?? 0) }}" required>
                            <span class="input-group-text">%</span>
                            @error('direct_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-text">Share of the sanctioned posts filled by open recruitment.</div>
                    </div>
                    <div class="col-md-4 col-lg-3 mb-3">
                        <label class="form-label" for="promotion_recruitment_pct">
                            Promotion — PR (%) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100"
                                   class="form-control @error('promotion_recruitment_pct') is-invalid @enderror"
                                   id="promotion_recruitment_pct" name="promotion_recruitment_pct"
                                   value="{{ old('promotion_recruitment_pct', $rule->promotion_recruitment_pct ?? 0) }}" required>
                            <span class="input-group-text">%</span>
                            @error('promotion_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-text">Share filled by promotion. Above 0 requires at least one feeder cadre in Step 3.</div>
                    </div>
                    <div class="col-md-4 col-lg-6 mb-3">
                        <span class="form-label d-block">DR + PR</span>
                        <div class="split-total-box border rounded px-3 py-2">
                            <span class="share-total" id="split-total">0%</span>
                            <span id="split-total-mark" aria-hidden="true"></span>
                            <span class="small text-body-secondary ms-2" id="split-total-hint"></span>
                        </div>
                        <div class="form-text">
                            Every sanctioned post is filled either directly or by promotion,
                            so DR&nbsp;+&nbsp;PR must total <strong>exactly 100%</strong>.
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
                        <label class="form-label" for="effective_from">Effective From <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                               id="effective_from" name="effective_from"
                               value="{{ old('effective_from', $rule->effective_from?->format('Y-m-d')) }}" required>
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">Effective To</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror"
                               id="effective_to" name="effective_to"
                               value="{{ old('effective_to', $rule->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Leave blank while in force.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="go_document">Government Order PDF</label>
                        <input type="file" accept="application/pdf" class="form-control @error('go_document') is-invalid @enderror"
                               id="go_document" name="go_document">
                        @if ($rule->go_document_path)
                            <div class="form-text">Current: <a href="{{ route('admin.recruitment-rules.download', $rule) }}">GO PDF</a> — uploading replaces it.</div>
                        @endif
                        @error('go_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                {{-- ============================================================ --}}
                {{-- Step 3 — the feeder cadres                                   --}}
                {{-- ============================================================ --}}
                <div class="d-flex justify-content-between align-items-end border-bottom pb-2 mb-3 mt-4">
                    <div>
                        <h2 class="h6 text-uppercase text-body-secondary mb-1">Step 3 · Feeder cadres (Schedule-IV)</h2>
                        <p class="text-body-secondary small mb-0">
                            The post(s) officers are promoted <strong>from</strong>, and the conditions each must meet.
                        </p>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" id="add-feeder">
                        + Add Feeder Cadre
                    </button>
                </div>

                @error('feeders')
                    <div class="alert alert-danger py-2" role="alert">{{ $message }}</div>
                @enderror

                <div class="feeder-grid">
                    {{-- Fixed layout, with the column widths declared once in the
                         colgroup. Under the default auto layout a long header note
                         either stretches the table past the page (clipping Action)
                         or overflows its own cell into the next one; fixed layout
                         makes every column honour its width and wrap inside it. --}}
                    <table class="table table-sm align-middle" id="feeders-table" style="table-layout:fixed; min-width:56rem">
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
                                    Feeder Cadre <span class="text-danger">*</span>
                                    <span class="th-note">Chosen from the Cadre master.</span>
                                </th>
                                <th>
                                    Promotion Share (%) <span class="text-danger">*</span>
                                    <span class="th-note">Percentage of promotion quota allocated to this feeder cadre.</span>
                                </th>
                                <th>
                                    Minimum Experience
                                    <span class="th-note">Value and unit, e.g. 5 Years.</span>
                                </th>
                                <th>
                                    Minimum Qualification
                                    <span class="th-note">From the Qualification master.</span>
                                </th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                @include('admin.recruitment-rules.partials.feeder-row', [
                                    'index' => $i, 'row' => $row,
                                    'cadres' => $cadres, 'qualifications' => $qualifications, 'units' => $units,
                                ])
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr id="feeders-empty" @class(['d-none' => $rows->isNotEmpty()])>
                                <td colspan="5" class="text-center text-body-secondary py-4">
                                    No feeder cadre yet. Press <strong>+ Add Feeder Cadre</strong> above,
                                    or leave this empty if the post is filled entirely by direct recruitment.
                                </td>
                            </tr>
                            <tr class="table-light">
                                <th class="text-end">Total</th>
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
                        <strong>Promotion Share (%)</strong> is the percentage of the promotion quota allocated to that
                        feeder cadre — not a percentage of all posts. The shares must total <strong>exactly 100%</strong>.
                        Example: a post with a 50% promotion quota drawn equally from two cadres is
                        <em>50% and 50%</em> here, not 25% and 25%.
                    </span>
                </div>

                @if ($qualifications->isEmpty())
                    <div class="alert alert-warning py-2 small">
                        No <strong>Active</strong> qualification exists yet, so Minimum Qualification cannot be set.
                        Add one under <a href="{{ route('admin.qualifications.index') }}">Administration → Qualifications</a>.
                    </div>
                @endif

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success">Save</button>
                    <a href="{{ route('admin.recruitment-rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    {{-- The blank row the Add button clones. __INDEX__ is replaced on insert. --}}
    <template id="feeder-row-template">
        @include('admin.recruitment-rules.partials.feeder-row', [
            'index' => '__INDEX__', 'row' => null,
            'cadres' => $cadres, 'qualifications' => $qualifications, 'units' => $units,
        ])
    </template>
@endsection

@push('scripts')
<script>
    /*
     * Feeder grid behaviour, all of it advisory: every rule enforced here is
     * enforced again server-side in RecruitmentRuleRequest. This exists so the
     * user is told before they press Save, not so the server can trust the page.
     *
     *   - Add / remove rows.
     *   - Keep the running Promotion Share total visible, with a ✓ at 100%.
     *   - Offer only cadres in the target's department, minus the target itself
     *     and minus cadres already chosen on another row — which is what makes
     *     a duplicate hard to create rather than merely rejected.
     */
    (function () {
        var form = document.getElementById('rule-form');
        var tbody = document.querySelector('#feeders-table tbody');
        var template = document.getElementById('feeder-row-template').innerHTML;
        var target = document.getElementById('cadre_id');
        var promotionPct = document.getElementById('promotion_recruitment_pct');
        var totalEl = document.getElementById('share-total');
        var markEl = document.getElementById('share-total-mark');
        var hintEl = document.getElementById('share-total-hint');
        var emptyRow = document.getElementById('feeders-empty');
        var directPct = document.getElementById('direct_recruitment_pct');
        var splitEl = document.getElementById('split-total');
        var splitMarkEl = document.getElementById('split-total-mark');
        var splitHintEl = document.getElementById('split-total-hint');
        var index = {{ $nextIndex }};

        /* DR + PR, shown the same way as the feeder share total so the two
           "must equal 100%" rules on this screen read identically. */
        function refreshSplit() {
            var dr = parseFloat(directPct.value);
            var pr = parseFloat(promotionPct.value);
            var total = (isNaN(dr) ? 0 : dr) + (isNaN(pr) ? 0 : pr);
            var rounded = Math.round(total * 100) / 100;
            var balanced = Math.abs(rounded - 100) < 0.01;

            splitEl.textContent = rounded + '%';
            splitEl.className = 'share-total ' + (balanced ? 'is-balanced' : 'is-unbalanced');
            splitMarkEl.textContent = balanced ? '✓' : '✗';
            splitHintEl.textContent = balanced
                ? 'Every post is accounted for.'
                : (rounded < 100
                    ? (Math.round((100 - rounded) * 100) / 100) + '% of the posts is unaccounted for.'
                    : 'Over-allocated by ' + (Math.round((rounded - 100) * 100) / 100) + '%.');
        }

        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr.feeder-row'));
        }

        function refreshTotal() {
            var all = rows();
            var total = 0;
            all.forEach(function (row) {
                var v = parseFloat(row.querySelector('.share-input').value);
                if (!isNaN(v)) { total += v; }
            });

            var rounded = Math.round(total * 100) / 100;
            var balanced = Math.abs(rounded - 100) < 0.01;
            var promotes = parseFloat(promotionPct.value) > 0;

            totalEl.textContent = rounded + '%';
            emptyRow.classList.toggle('d-none', all.length > 0);

            if (all.length === 0) {
                totalEl.className = 'share-total';
                markEl.textContent = '';
                hintEl.textContent = promotes
                    ? 'A promotion quota above 0% needs at least one feeder cadre.'
                    : 'No feeder cadre needed — this post is filled entirely by direct recruitment.';
                return;
            }

            totalEl.className = 'share-total ' + (balanced ? 'is-balanced' : 'is-unbalanced');
            markEl.textContent = balanced ? '✓' : '✗';
            hintEl.textContent = balanced
                ? 'The promotion quota is fully allocated.'
                : (rounded < 100
                    ? 'Must total exactly 100% — ' + (Math.round((100 - rounded) * 100) / 100) + '% still unallocated.'
                    : 'Must total exactly 100% — over-allocated by ' + (Math.round((rounded - 100) * 100) / 100) + '%.');
        }

        /* Hide cadres that cannot legally be chosen on this row. */
        function refreshCadreOptions() {
            var department = target.selectedOptions[0] ? target.selectedOptions[0].dataset.department : null;
            var taken = rows().map(function (r) { return r.querySelector('.cadre-select').value; })
                              .filter(function (v) { return v !== ''; });

            rows().forEach(function (row) {
                var select = row.querySelector('.cadre-select');
                var mine = select.value;

                Array.prototype.slice.call(select.options).forEach(function (option) {
                    if (option.value === '') { return; }
                    var sameDepartment = department !== null && option.dataset.department === department;
                    var isTarget = option.value === target.value;
                    var takenElsewhere = taken.indexOf(option.value) !== -1 && option.value !== mine;
                    option.hidden = !sameDepartment || isTarget || takenElsewhere;
                    option.disabled = option.hidden;
                });

                // A cadre that has become ineligible (the target changed under it)
                // is cleared rather than left showing a value that cannot be saved.
                if (mine !== '' && select.options[select.selectedIndex] && select.options[select.selectedIndex].hidden) {
                    select.value = '';
                }

                select.dispatchEvent(new CustomEvent('ss:refresh'));
            });
        }

        function refresh() {
            refreshCadreOptions();
            refreshTotal();
            refreshSplit();
        }

        document.getElementById('add-feeder').addEventListener('click', function () {
            tbody.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', index++));
            refresh();
            var added = rows()[rows().length - 1];
            var input = added.querySelector('.ss-input') || added.querySelector('.cadre-select');
            if (input) { input.focus(); }
        });

        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.remove-feeder')) {
                e.target.closest('tr.feeder-row').remove();
                refresh();
            }
        });

        tbody.addEventListener('input', function (e) {
            if (e.target.classList.contains('share-input')) { refreshTotal(); }
        });
        tbody.addEventListener('change', function (e) {
            if (e.target.classList.contains('cadre-select')) { refresh(); }
        });

        target.addEventListener('change', refresh);
        promotionPct.addEventListener('input', function () { refreshTotal(); refreshSplit(); });
        directPct.addEventListener('input', refreshSplit);

        /* Last line of defence before a pointless round trip. The server checks
           the same thing; this just saves the user the wait. */
        form.addEventListener('submit', function (e) {
            /* DR + PR first: it sits above the feeder grid, so reporting it
               first means the user is never scrolled past an error to reach
               a later one. */
            var dr = parseFloat(directPct.value);
            var pr = parseFloat(promotionPct.value);
            var split = (isNaN(dr) ? 0 : dr) + (isNaN(pr) ? 0 : pr);

            if (Math.abs(Math.round(split * 100) / 100 - 100) >= 0.01) {
                e.preventDefault();
                splitEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
                splitHintEl.classList.add('fw-semibold');

                return;
            }

            var total = 0;
            rows().forEach(function (row) {
                var v = parseFloat(row.querySelector('.share-input').value);
                if (!isNaN(v)) { total += v; }
            });
            var promotes = parseFloat(promotionPct.value) > 0;

            if (promotes && rows().length > 0 && Math.abs(Math.round(total * 100) / 100 - 100) >= 0.01) {
                e.preventDefault();
                totalEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
                hintEl.classList.add('fw-semibold');
            }
        });

        refresh();
    })();
</script>
@endpush
