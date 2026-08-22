@extends('layouts.admin')

@section('title', 'Committee Configurations')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Committee Configurations / समिति संरचना</h1>
        <a href="{{ route('admin.committee-configurations.create') }}" class="btn btn-success btn-sm">+ New</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Committee Type</th><th>Members (min–max)</th><th>Quorum</th><th>Voting</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($configurations as $configuration)
                        <tr>
                            <td>{{ $configuration->committeeType?->name_en }}</td>
                            <td>{{ $configuration->min_members }}–{{ $configuration->max_members }}</td>
                            <td>{{ $configuration->quorum }}</td>
                            <td>{{ $configuration->voting_method }}</td>
                            <td><x-status-badge :status="$configuration->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.committee-configurations.edit', $configuration) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.committee-configurations.destroy', $configuration) }}" class="d-inline"
                                      data-confirm="Delete this configuration?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No configurations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $configurations->links() }}</div>
@endsection
