@extends('layouts.admin')

@section('title', 'Designations')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Designations / पदनाम</h1>
        <a href="{{ route('admin.designations.create') }}" class="btn btn-success btn-sm">+ New Designation</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Name</th><th>Code</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($designations as $designation)
                        <tr>
                            <td>{{ $designation->name }}</td>
                            <td>{{ $designation->code ?? '—' }}</td>
                            <td><x-status-badge :status="$designation->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.designations.edit', $designation) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.designations.destroy', $designation) }}" class="d-inline"
                                      data-confirm="Delete this designation?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No designations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $designations->links() }}</div>
@endsection
