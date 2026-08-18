@extends('layouts.app')

@section('title', $proposal->exists ? 'Edit Pre-DPC Proposal' : 'New Pre-DPC Proposal')

@php
    $postsData = old('posts', $proposal->exists
        ? $proposal->posts->map(fn ($p) => [
            'post_name' => $p->post_name,
            'cadre_id' => $p->cadre_id,
            'class_level' => $p->class_level->value,
            'pay_scale_id' => $p->pay_scale_id,
            'direct_recruitment_pct' => $p->direct_recruitment_pct,
            'promotion_recruitment_pct' => $p->promotion_recruitment_pct,
            'feeders' => $p->feeders->map(fn ($f) => [
                'feeder_name' => $f->feeder_name,
                'feeder_cadre_id' => $f->feeder_cadre_id,
                'promotion_percentage' => $f->promotion_percentage,
                'min_experience_years' => $f->min_experience_years,
                'qualification_id' => $f->qualification_id,
                // The label a re-opened draft falls back to when its stored
                // reference is no longer offered by the master.
                'min_qualification' => $f->qualificationLabel(),
            ])->all(),
        ])->all()
        : []);
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-3">{{ $proposal->exists ? 'Edit Pre-DPC Proposal (v'.$proposal->version.')' : 'New Pre-DPC Proposal' }}</h1>

            <form method="POST" action="{{ $proposal->exists ? route('pre-dpc.update', $proposal) : route('pre-dpc.store') }}">
                @csrf
                @if ($proposal->exists) @method('PUT') @endif

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label" for="government_order_number">Schedule-IV Government Order No.</label>
                            <input type="text" class="form-control" id="government_order_number" name="government_order_number" value="{{ old('government_order_number', $proposal->government_order_number) }}">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="effective_from">Effective From</label>
                            <input type="date" class="form-control" id="effective_from" name="effective_from" value="{{ old('effective_from', $proposal->effective_from?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-12"><small class="text-muted">GO number &amp; effective date are required before you can submit for approval.</small></div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Sanctioned Posts for Promotion</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-post" @disabled($cadres->isEmpty())>+ Add Post</button>
                </div>

                {{-- The posts and feeder cadres are now chosen from the Cadre
                     master, so a department whose master is empty cannot fill
                     this form in at all. Saying so plainly — and naming who
                     populates it — is the difference between a screen that is
                     blocked and a screen that appears broken. --}}
                @if ($cadres->isEmpty())
                    <div class="alert alert-warning" role="alert">
                        <strong>No post is registered for your department yet.</strong>
                        <p class="mb-0 small">
                            Posts and feeder cadres on this form are chosen from the <strong>Cadre master</strong>,
                            which the CGPSC Administration maintains for each department. Ask the Commission to
                            register your department's sanctioned posts, then return to this screen.
                        </p>
                    </div>
                @endif

                <div id="posts"></div>

                <button type="submit" class="btn btn-success mt-3" @disabled($cadres->isEmpty())>Save Draft</button>
                <a href="{{ route('pre-dpc.index') }}" class="btn btn-outline-secondary mt-3">Cancel</a>
            </form>
        </div>
    </div>

    <template id="post-template">
        <div class="card border-0 shadow-sm mb-3 post-card">
            <div class="card-body">
                {{-- Two rows, not one.
                     DR % and PR % were col-md-1 boxes too narrow to show
                     "100.00" and labelled only by an abbreviation. They now
                     have room and a full label, and — since they must total
                     100% — they sit together on their own row rather than
                     squeezed at the end of the identity fields. --}}
                <div class="row">
                    <div class="col-md-5 mb-2">
                        <label class="form-label">Post Name <span class="text-danger">*</span></label>
                        <select class="form-select post-cadre" name="posts[__P__][cadre_id]" required
                                data-searchable data-searchable-placeholder="Search posts…">
                            <option value="">— Select —</option>
                            @foreach ($cadres as $cadre)
                                <option value="{{ $cadre->id }}"
                                        data-class="{{ $cadre->class_level->value }}"
                                        data-pay-scale="{{ $cadre->pay_scale_id }}">{{ $cadre->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">The post officers are promoted <strong>into</strong>, from the Cadre master.</div>
                    </div>
                    <div class="col-md-3 mb-2"><label class="form-label">Class <span class="text-danger">*</span></label>
                        <select class="form-select post-class" name="posts[__P__][class_level]" required>
                            @foreach ($classes as $class)<option value="{{ $class->value }}">{{ $class->label() }}</option>@endforeach
                        </select>
                        <div class="form-text">Filled in from the post; change only if the Order differs.</div></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Pay Scale</label>
                        <select class="form-select post-pay-scale" name="posts[__P__][pay_scale_id]"><option value="">— None —</option>
                            @foreach ($payScales as $ps)<option value="{{ $ps->id }}">{{ $ps->label }}</option>@endforeach
                        </select></div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2"><label class="form-label">Direct Recruitment — DR (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" class="form-control dr-pct" name="posts[__P__][direct_recruitment_pct]" value="0" required>
                            <span class="input-group-text">%</span>
                        </div></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Promotion — PR (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" class="form-control pr-pct" name="posts[__P__][promotion_recruitment_pct]" value="100" required>
                            <span class="input-group-text">%</span>
                        </div></div>
                    <div class="col-md-5 mb-2"><span class="form-label d-block">DR + PR</span>
                        <div class="split-total-box border rounded px-3 py-2">
                            <span class="share-total split-total">100%</span>
                            <span class="split-total-mark" aria-hidden="true"></span>
                            <span class="small text-body-secondary ms-2">must total exactly 100%</span>
                        </div></div>
                    <div class="col-md-1 mb-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-post">×</button></div>
                </div>
                {{-- Feeder cadres.
                     ------------------------------------------------------------
                     Every column is labelled. This grid previously had no header
                     row at all — four bare inputs identified only by placeholder
                     text, which disappears the moment anything is typed, so a
                     half-filled row was unreadable. The labels are the same words
                     the Recruitment Rule master uses for the same four fields,
                     because this is the screen those rules are projected from and
                     a department should not have to learn two vocabularies. --}}
                <div class="mt-3 pt-2 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <span class="small fw-semibold">Feeder Cadres</span>
                            <span class="small text-body-secondary ms-1">
                                — the post(s) officers are promoted <strong>from</strong>, and the conditions each must meet.
                            </span>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm add-feeder text-nowrap" data-post="__P__">+ Add Feeder Cadre</button>
                    </div>
                    <div class="feeder-grid">
                        <table class="table table-sm mb-0 align-middle" style="table-layout:fixed; min-width:52rem">
                            <colgroup>
                                <col style="width:30%"><col style="width:17%"><col style="width:18%"><col style="width:27%"><col style="width:8%">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th>Feeder Cadre <span class="text-danger">*</span>
                                        <span class="th-note">Chosen from the Cadre master.</span></th>
                                    <th>Promotion Share (%) <span class="text-danger">*</span>
                                        <span class="th-note">Share of the promotion quota from this cadre.</span></th>
                                    <th>Minimum Experience <span class="text-danger">*</span>
                                        <span class="th-note">In years, as Schedule-IV states it.</span></th>
                                    <th>Minimum Qualification
                                        <span class="th-note">From the Qualification master.</span></th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody class="feeders" data-post="__P__"></tbody>
                            <tfoot>
                                <tr class="feeders-empty">
                                    <td colspan="5" class="text-center text-body-secondary small py-3">
                                        No feeder cadre yet. Press <strong>+ Add Feeder Cadre</strong>,
                                        or leave this empty if the post is filled entirely by direct recruitment.
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <template id="feeder-template">
        <tr class="feeder-row">
            <td>
                <select class="form-select form-select-sm feeder-cadre" name="posts[__P__][feeders][__F__][feeder_cadre_id]" required
                        data-searchable data-searchable-placeholder="Search cadres…">
                    <option value="">— Select —</option>
                    @foreach ($cadres as $cadre)
                        <option value="{{ $cadre->id }}">{{ $cadre->name }}</option>
                    @endforeach
                </select>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.01" min="0" max="100" class="form-control" name="posts[__P__][feeders][__F__][promotion_percentage]" value="100" required>
                    <span class="input-group-text">%</span>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" min="0" max="60" class="form-control" name="posts[__P__][feeders][__F__][min_experience_years]" value="5" required>
                    <span class="input-group-text">yrs</span>
                </div>
            </td>
            <td>
                <select class="form-select form-select-sm feeder-qualification" name="posts[__P__][feeders][__F__][qualification_id]"
                        data-searchable data-searchable-placeholder="Search qualifications…">
                    <option value="">— None —</option>
                    @foreach ($qualifications as $qualification)
                        <option value="{{ $qualification->id }}">{{ $qualification->name_en }}</option>
                    @endforeach
                </select>
            </td>
            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm remove-feeder">×</button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
    (function () {
        const postsEl = document.getElementById('posts');
        const postTpl = document.getElementById('post-template').innerHTML;
        const feederTpl = document.getElementById('feeder-template').innerHTML;
        let p = 0;

        function addPost(prefill) {
            const idx = p++;
            const wrap = document.createElement('div');
            wrap.innerHTML = postTpl.replaceAll('__P__', idx);
            postsEl.appendChild(wrap.firstElementChild);
            refreshEmpty(postsEl.lastElementChild.querySelector('tbody.feeders'));
            // A new card starts at DR 0 / PR 100, which is balanced — say so
            // now rather than leaving the ✓ blank until the user types.
            refreshSplit(postsEl.lastElementChild);
            return idx;
        }
        function addFeeder(postIdx, tbody) {
            const f = tbody.querySelectorAll('tr').length;
            const row = document.createElement('template');
            row.innerHTML = feederTpl.replaceAll('__P__', postIdx).replaceAll('__F__', f);
            tbody.appendChild(row.content.firstElementChild);
            refreshEmpty(tbody);
            return tbody.lastElementChild;
        }

        /* searchable-select.js enhances rows added after load by itself, via a
           MutationObserver — nothing here needs to attach it. What it cannot
           know is that we have *changed* a select's options or value after it
           was enhanced, so tell it to re-read them. */
        function enhance(scope) {
            scope.querySelectorAll('select[data-searchable]').forEach(function (select) {
                select.dispatchEvent(new CustomEvent('ss:refresh'));
            });
        }

        /* "No feeder cadre yet" belongs in the footer only while there is none. */
        function refreshEmpty(tbody) {
            const empty = tbody.parentElement.querySelector('.feeders-empty');
            if (empty) { empty.classList.toggle('d-none', tbody.querySelectorAll('tr').length > 0); }
        }

        /* Class and Pay Scale are the cadre's own attributes, so choosing the
           post fills them in. They stay editable: the Pre-DPC records what the
           Government Order says, and if the Order disagrees with the master the
           Order is what is being registered. */
        function applyCadreDefaults(card) {
            const select = card.querySelector('.post-cadre');
            const option = select.selectedOptions[0];
            if (!option || !option.value) { return; }

            const cls = card.querySelector('.post-class');
            const scale = card.querySelector('.post-pay-scale');
            if (option.dataset.class) { cls.value = option.dataset.class; }
            scale.value = option.dataset.payScale || '';
        }

        /* DR + PR per post card, advisory only — SavePreDpcProposalRequest
           enforces the same rule, and the approval that projects these posts
           into the Recruitment Rule master enforces it again there. */
        function refreshSplit(card) {
            const dr = parseFloat(card.querySelector('.dr-pct').value);
            const pr = parseFloat(card.querySelector('.pr-pct').value);
            const total = Math.round(((isNaN(dr) ? 0 : dr) + (isNaN(pr) ? 0 : pr)) * 100) / 100;
            const balanced = Math.abs(total - 100) < 0.01;
            const totalEl = card.querySelector('.split-total');

            totalEl.textContent = total + '%';
            totalEl.className = 'share-total split-total ' + (balanced ? 'is-balanced' : 'is-unbalanced');
            card.querySelector('.split-total-mark').textContent = balanced ? '✓' : '✗';
        }

        postsEl.addEventListener('input', function (e) {
            if (e.target.classList.contains('dr-pct') || e.target.classList.contains('pr-pct')) {
                refreshSplit(e.target.closest('.post-card'));
            }
        });

        postsEl.addEventListener('change', function (e) {
            if (e.target.classList.contains('post-cadre')) {
                applyCadreDefaults(e.target.closest('.post-card'));
            }
        });

        postsEl.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-post')) e.target.closest('.post-card').remove();
            if (e.target.classList.contains('remove-feeder')) {
                const tbody = e.target.closest('tbody.feeders');
                e.target.closest('tr').remove();
                refreshEmpty(tbody);
            }
            if (e.target.classList.contains('add-feeder')) {
                const card = e.target.closest('.post-card');
                addFeeder(card.querySelector('[name^="posts["]').name.match(/posts\[(\d+)\]/)[1], card.querySelector('tbody.feeders'));
            }
        });
        document.getElementById('add-post').addEventListener('click', () => addPost());

        // Prefill from server data. With an empty Cadre master there is nothing
        // to select, so no blank card is opened — the warning above stands on
        // its own rather than sitting over a form that cannot be completed.
        const seed = @json($postsData);
        const hasCadres = {{ $cadres->isEmpty() ? 'false' : 'true' }};
        if (seed.length === 0) { if (hasCadres) { addPost(); } }
        else {
            seed.forEach(function (post) {
                const idx = addPost();
                const card = postsEl.lastElementChild;
                /* Selected by id now, not by typed text.
                   `set` is deliberate about a value the dropdown does not
                   offer: a proposal drafted before the master was in use, or
                   one naming a cadre since deactivated, would otherwise fall
                   silently back to "— Select —" and the user would re-save a
                   blank over real data. Instead the row keeps the value and
                   says where it came from. */
                function set(el, value, label) {
                    if (!el || value === null || value === undefined || value === '') { return; }
                    el.value = value;
                    if (el.value !== String(value) && label) {
                        el.insertAdjacentHTML('afterbegin',
                            `<option value="${value}" selected>${label} (no longer in the master)</option>`);
                        el.value = value;
                    }
                }

                set(card.querySelector(`[name="posts[${idx}][cadre_id]"]`), post.cadre_id, post.post_name);
                if (post.class_level) card.querySelector(`[name="posts[${idx}][class_level]"]`).value = post.class_level;
                if (post.pay_scale_id) card.querySelector(`[name="posts[${idx}][pay_scale_id]"]`).value = post.pay_scale_id;
                card.querySelector(`[name="posts[${idx}][direct_recruitment_pct]"]`).value = post.direct_recruitment_pct ?? 0;
                card.querySelector(`[name="posts[${idx}][promotion_recruitment_pct]"]`).value = post.promotion_recruitment_pct ?? 100;
                refreshSplit(card);
                const tbody = card.querySelector('tbody.feeders');
                (post.feeders || []).forEach(function (feeder, f) {
                    const tr = addFeeder(idx, tbody);
                    set(tr.querySelector(`[name="posts[${idx}][feeders][${f}][feeder_cadre_id]"]`), feeder.feeder_cadre_id, feeder.feeder_name);
                    tr.querySelector(`[name="posts[${idx}][feeders][${f}][promotion_percentage]"]`).value = feeder.promotion_percentage ?? 100;
                    tr.querySelector(`[name="posts[${idx}][feeders][${f}][min_experience_years]"]`).value = feeder.min_experience_years ?? 5;
                    set(tr.querySelector(`[name="posts[${idx}][feeders][${f}][qualification_id]"]`), feeder.qualification_id, feeder.min_qualification);
                });
                enhance(card);
            });
        }
    })();
</script>
@endpush
