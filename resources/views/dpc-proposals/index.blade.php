@extends('layouts.app')

@section('title', 'DPC Proposals')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4 mb-0">DPC Proposals / डी.पी.सी. प्रस्ताव</h1>
                <a href="{{ route('dpc-proposals.create') }}" class="btn btn-success btn-sm">+ New Proposal</a>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            <tr><th>DPC ID</th><th>Type</th><th>Post From → To</th><th>Proposal Date</th><th>Status</th><th class="text-end">Action</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($proposals as $proposal)
                                <tr>
                                    <td>{{ $proposal->dpc_code ?? '—' }}</td>
                                    <td>{{ $proposal->dpcType->name_en }}</td>
                                    <td>{{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}</td>
                                    <td>{{ $proposal->proposal_date->format('d-m-Y') }}</td>
                                    <td><span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span></td>
                                    <td class="text-end"><a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-primary btn-sm">Open</a></td>
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
