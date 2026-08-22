@extends('layouts.app')

@section('title', 'Pre-DPC Proposal v' . $proposal->version)

{{-- Actions are gated by `@can`, which asks PreDpcProposalPolicy — the same
     class the controller authorises against (TD-26, closed 2026-08-18). This
     screen used to re-derive the rules from the actor's role and the proposal's
     status, which meant every rule was stated twice, in two idioms, and could
     drift apart one edit at a time. --}}

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">Pre-DPC Proposal — v{{ $proposal->version }}</h1>
                <span class="badge {{ $proposal->status->badgeClass() }} fs-6">{{ $proposal->status->label() }}</span>
            </div>

            @if ($proposal->status->value === 'returned' && $proposal->return_remarks)
                <div class="alert alert-warning"><strong>Returned:</strong> {{ $proposal->return_remarks }}</div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Government Order No.</dt><dd class="col-sm-9">{{ $proposal->government_order_number ?? '—' }}</dd>
                        <dt class="col-sm-3">Effective From</dt><dd class="col-sm-9">{{ $proposal->effective_from?->format('d-m-Y') ?? '—' }}</dd>
                        <dt class="col-sm-3">Current Version</dt><dd class="col-sm-9">{{ $proposal->is_current ? 'Yes' : 'No' }}</dd>
                        @if ($proposal->approved_at)
                            <dt class="col-sm-3">Approved</dt><dd class="col-sm-9">{{ $proposal->approved_at->format('d-m-Y H:i') }} by {{ $proposal->approver?->name }} (signature #{{ $proposal->signature_id }})</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Sanctioned Posts</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Post</th><th>Class</th><th>Pay Scale</th><th>DR %</th><th>PR %</th><th>Feeders</th></tr></thead>
                            <tbody>
                                @forelse ($proposal->posts as $post)
                                    <tr>
                                        <td>{{ $post->post_name }}</td>
                                        <td>{{ $post->class_level->label() }}</td>
                                        <td>{{ $post->payScale?->label ?? '—' }}</td>
                                        <td>{{ $post->direct_recruitment_pct }}</td>
                                        <td>{{ $post->promotion_recruitment_pct }}</td>
                                        <td>
                                            {{-- Read through the label methods, never the raw text: they
                                                 prefer the master row and fall back to what was typed, so a
                                                 proposal written before the screen used the masters still
                                                 shows what it named. --}}
                                            @forelse ($post->feeders as $feeder)
                                                <div class="small">
                                                    {{ $feeder->feederLabel() }} — {{ $feeder->promotion_percentage }}%,
                                                    {{ $feeder->min_experience_years }} yr
                                                    @if ($feeder->qualificationLabel())
                                                        , {{ $feeder->qualificationLabel() }}
                                                    @endif
                                                </div>
                                            @empty
                                                <span class="text-body-secondary small">Direct recruitment only</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-3">No posts.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Workflow actions --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2">
                    @can('update', $proposal)
                        <a href="{{ route('pre-dpc.edit', $proposal) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                        <form method="POST" action="{{ route('pre-dpc.submit', $proposal) }}">@csrf<button class="btn btn-success btn-sm">Submit for Approval</button></form>
                        <form method="POST" action="{{ route('pre-dpc.cancel', $proposal) }}" data-confirm="Cancel this proposal?">@csrf<button class="btn btn-outline-danger btn-sm">Cancel</button></form>
                    @endcan

                    @can('approve', $proposal)
                        <form method="POST" action="{{ route('pre-dpc.approve', $proposal) }}" data-confirm="Approve and digitally sign this proposal?">@csrf<button class="btn btn-success btn-sm">Approve &amp; Sign</button></form>
                    @endcan

                    @can('amend', $proposal)
                        <form method="POST" action="{{ route('pre-dpc.amend', $proposal) }}">@csrf<button class="btn btn-outline-secondary btn-sm">Amend (new version)</button></form>
                    @endcan

                    @if (! $proposal->status->isEditable() && $proposal->status->value !== 'submitted' && $proposal->status->value !== 'approved')
                        <span class="text-muted small align-self-center">No actions available in this state.</span>
                    @endif
                </div>

                @can('return', $proposal)
                    <div class="card-body border-top">
                        <form method="POST" action="{{ route('pre-dpc.return', $proposal) }}" class="row g-2">
                            @csrf
                            <div class="col-md-9">
                                <input type="text" class="form-control form-control-sm @error('return_remarks') is-invalid @enderror" name="return_remarks" placeholder="Remarks (required to return)">
                                @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3"><button class="btn btn-warning btn-sm w-100">Return for Correction</button></div>
                        </form>
                    </div>
                @endcan
            </div>

            {{-- Transition history --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">Workflow History</h2>
                    <ul class="list-group list-group-flush">
                        @forelse ($transitions as $t)
                            <li class="list-group-item px-0">
                                <span class="badge text-bg-light">{{ $t->event }}</span>
                                {{ $t->from_state }} → {{ $t->to_state }}
                                <span class="text-muted small">by {{ $t->actor?->name }} on {{ $t->occurred_at->format('d-m-Y H:i') }}</span>
                                @if ($t->signature_id)<span class="badge text-bg-success">signed #{{ $t->signature_id }}</span>@endif
                                @if ($t->remarks)<div class="small text-muted">{{ $t->remarks }}</div>@endif
                            </li>
                        @empty
                            <li class="list-group-item px-0 text-muted">No transitions yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <a href="{{ route('pre-dpc.index') }}" class="btn btn-link mt-2">← Back to list</a>
        </div>
    </div>
@endsection
