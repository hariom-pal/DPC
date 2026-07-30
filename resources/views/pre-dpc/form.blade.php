@extends('layouts.app')

@section('title', $proposal->exists ? 'Edit Pre-DPC Proposal' : 'New Pre-DPC Proposal')

@php
    $postsData = old('posts', $proposal->exists
        ? $proposal->posts->map(fn ($p) => [
            'post_name' => $p->post_name,
            'class_level' => $p->class_level->value,
            'pay_scale_id' => $p->pay_scale_id,
            'direct_recruitment_pct' => $p->direct_recruitment_pct,
            'promotion_recruitment_pct' => $p->promotion_recruitment_pct,
            'feeders' => $p->feeders->map(fn ($f) => [
                'feeder_name' => $f->feeder_name,
                'feeder_cadre_id' => $f->feeder_cadre_id,
                'promotion_percentage' => $f->promotion_percentage,
                'min_experience_years' => $f->min_experience_years,
                'min_qualification' => $f->min_qualification,
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
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-post">+ Add Post</button>
                </div>

                <div id="posts"></div>

                <button type="submit" class="btn btn-success mt-3">Save Draft</button>
                <a href="{{ route('pre-dpc.index') }}" class="btn btn-outline-secondary mt-3">Cancel</a>
            </form>
        </div>
    </div>

    <template id="post-template">
        <div class="card border-0 shadow-sm mb-3 post-card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-2"><label class="form-label">Post Name</label>
                        <input type="text" class="form-control" name="posts[__P__][post_name]" required></div>
                    <div class="col-md-2 mb-2"><label class="form-label">Class</label>
                        <select class="form-select" name="posts[__P__][class_level]" required>
                            @foreach ($classes as $class)<option value="{{ $class->value }}">{{ $class->label() }}</option>@endforeach
                        </select></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Pay Scale</label>
                        <select class="form-select" name="posts[__P__][pay_scale_id]"><option value="">— None —</option>
                            @foreach ($payScales as $ps)<option value="{{ $ps->id }}">{{ $ps->label }}</option>@endforeach
                        </select></div>
                    <div class="col-md-1 mb-2"><label class="form-label">DR %</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control" name="posts[__P__][direct_recruitment_pct]" value="0" required></div>
                    <div class="col-md-1 mb-2"><label class="form-label">PR %</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control" name="posts[__P__][promotion_recruitment_pct]" value="100" required></div>
                    <div class="col-md-1 mb-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm remove-post">×</button></div>
                </div>
                <div class="ms-3 mt-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small fw-semibold">Feeder Cadres</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm add-feeder" data-post="__P__">+ Feeder</button>
                    </div>
                    <table class="table table-sm mt-1 mb-0"><tbody class="feeders" data-post="__P__"></tbody></table>
                </div>
            </div>
        </div>
    </template>

    <template id="feeder-template">
        <tr>
            <td><input type="text" class="form-control form-control-sm" name="posts[__P__][feeders][__F__][feeder_name]" placeholder="Feeder post" required></td>
            <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="posts[__P__][feeders][__F__][promotion_percentage]" value="100" required></td>
            <td><input type="number" min="0" max="60" class="form-control form-control-sm" name="posts[__P__][feeders][__F__][min_experience_years]" value="5" required></td>
            <td><input type="text" class="form-control form-control-sm" name="posts[__P__][feeders][__F__][min_qualification]" placeholder="Min qualification"></td>
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
            return idx;
        }
        function addFeeder(postIdx, tbody) {
            const f = tbody.querySelectorAll('tr').length;
            const row = document.createElement('template');
            row.innerHTML = feederTpl.replaceAll('__P__', postIdx).replaceAll('__F__', f);
            tbody.appendChild(row.content.firstElementChild);
        }

        postsEl.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-post')) e.target.closest('.post-card').remove();
            if (e.target.classList.contains('remove-feeder')) e.target.closest('tr').remove();
            if (e.target.classList.contains('add-feeder')) {
                const card = e.target.closest('.post-card');
                addFeeder(card.querySelector('[name^="posts["]').name.match(/posts\[(\d+)\]/)[1], card.querySelector('tbody.feeders'));
            }
        });
        document.getElementById('add-post').addEventListener('click', () => addPost());

        // Prefill from server data
        const seed = @json($postsData);
        if (seed.length === 0) { addPost(); }
        else {
            seed.forEach(function (post) {
                const idx = addPost();
                const card = postsEl.lastElementChild;
                card.querySelector(`[name="posts[${idx}][post_name]"]`).value = post.post_name ?? '';
                if (post.class_level) card.querySelector(`[name="posts[${idx}][class_level]"]`).value = post.class_level;
                if (post.pay_scale_id) card.querySelector(`[name="posts[${idx}][pay_scale_id]"]`).value = post.pay_scale_id;
                card.querySelector(`[name="posts[${idx}][direct_recruitment_pct]"]`).value = post.direct_recruitment_pct ?? 0;
                card.querySelector(`[name="posts[${idx}][promotion_recruitment_pct]"]`).value = post.promotion_recruitment_pct ?? 100;
                const tbody = card.querySelector('tbody.feeders');
                (post.feeders || []).forEach(function (feeder, f) {
                    addFeeder(idx, tbody);
                    const tr = tbody.lastElementChild;
                    tr.querySelector(`[name="posts[${idx}][feeders][${f}][feeder_name]"]`).value = feeder.feeder_name ?? '';
                    tr.querySelector(`[name="posts[${idx}][feeders][${f}][promotion_percentage]"]`).value = feeder.promotion_percentage ?? 100;
                    tr.querySelector(`[name="posts[${idx}][feeders][${f}][min_experience_years]"]`).value = feeder.min_experience_years ?? 5;
                    if (feeder.min_qualification) tr.querySelector(`[name="posts[${idx}][feeders][${f}][min_qualification]"]`).value = feeder.min_qualification;
                });
            });
        }
    })();
</script>
@endpush
