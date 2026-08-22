@extends('layouts.admin')

@section('title', 'Cadres')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Cadres / संवर्ग</h1>
        <a href="{{ route('admin.cadres.create') }}" class="btn btn-success btn-sm">+ New Cadre</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Name</th><th>Department</th><th>Class</th><th>Pay Scale</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($cadres as $cadre)
                        <tr>
                            <td>{{ $cadre->name }}</td>
                            <td>{{ $cadre->department?->name }}</td>
                            <td>{{ $cadre->class_level->label() }}</td>
                            <td>{{ $cadre->payScale?->label ?? '—' }}</td>
                            <td><x-status-badge :status="$cadre->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.cadres.edit', $cadre) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.cadres.destroy', $cadre) }}" class="d-inline"
                                      data-confirm="Delete this cadre?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No cadres yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $cadres->links() }}</div>
@endsection
