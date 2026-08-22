@extends('layouts.admin')

@section('title', 'Departments')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">Department Master / विभाग</h1>
            <p class="text-muted small mb-0">
                The list offered on the registration screen. The code is the prefix of every DPC ID issued to the department.
            </p>
        </div>
        <a href="{{ route('admin.departments.create') }}" class="btn btn-success btn-sm">+ New Department</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>District</th>
                        <th>Contact Email</th>
                        <th class="text-center">Users</th>
                        <th class="text-center">Cadres</th>
                        <th class="text-center">DPCs</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($departments as $department)
                        <tr>
                            <td><span class="badge text-bg-secondary">{{ $department->code }}</span></td>
                            <td>{{ $department->name }}</td>
                            <td>{{ $department->district?->name_en ?? '—' }}</td>
                            <td class="text-break">{{ $department->email ?? '—' }}</td>
                            <td class="text-center">{{ $department->users_count }}</td>
                            <td class="text-center">{{ $department->cadres_count }}</td>
                            <td class="text-center">{{ $department->dpc_proposals_count }}</td>
                            <td><span class="badge text-bg-light border">{{ $department->status->label() }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('admin.departments.edit', $department) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.departments.destroy', $department) }}" class="d-inline"
                                      data-confirm="Delete this department?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No departments yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $departments->links() }}</div>
@endsection
