@extends('layouts.app')

@section('title', 'Pre-DPC Proposals')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">Pre-DPC Proposals / प्री-डी.पी.सी. प्रस्ताव</h1>
                <a href="{{ route('pre-dpc.create') }}" class="btn btn-success btn-sm">+ New Proposal</a>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            <tr><th>Version</th><th>GO No.</th><th>Status</th><th>Current</th><th>Approved</th><th class="text-end">Action</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($proposals as $proposal)
                                <tr>
                                    <td>v{{ $proposal->version }}</td>
                                    <td>{{ $proposal->government_order_number ?? '—' }}</td>
                                    <td><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                                    <td>{!! $proposal->is_current ? '<span class="badge text-bg-primary">Current</span>' : '' !!}</td>
                                    <td>{{ $proposal->approved_at?->format('d-m-Y') ?? '—' }}</td>
                                    <td class="text-end"><a href="{{ route('pre-dpc.show', $proposal) }}" class="btn btn-outline-primary btn-sm">Open</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">No proposals yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">{{ $proposals->links() }}</div>
        </div>
    </div>
@endsection
