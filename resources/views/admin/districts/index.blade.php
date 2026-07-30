@extends('layouts.admin')

@section('title', 'Districts')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Districts / जिले</h1>
        <a href="{{ route('admin.districts.create') }}" class="btn btn-success btn-sm">+ New District</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Name (EN)</th>
                        <th>नाम (HI)</th>
                        <th>LGD Code</th>
                        <th>State LGD</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($districts as $district)
                        <tr>
                            <td>{{ $district->name_en }}</td>
                            <td>{{ $district->name_hi }}</td>
                            <td>{{ $district->lgd_code ?? '—' }}</td>
                            <td>{{ $district->state_lgd_code }}</td>
                            <td><x-status-badge :status="$district->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.districts.edit', $district) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.districts.destroy', $district) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this district?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No districts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $districts->links() }}</div>
@endsection
