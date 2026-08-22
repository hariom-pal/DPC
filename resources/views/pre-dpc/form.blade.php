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

                {{-- The saved posts, and whether this department has any cadre in the
                     master at all, travel as data — see public/js/forms/pre-dpc-posts.js. --}}
                <div id="posts"
                     data-seed="{{ json_encode($postsData) }}"
                     data-has-cadres="{{ $cadres->isEmpty() ? '0' : '1' }}"></div>

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
    <script src="{{ asset('js/forms/pre-dpc-posts.js') }}" defer></script>
@endpush
