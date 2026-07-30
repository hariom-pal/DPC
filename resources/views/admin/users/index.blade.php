@extends('layouts.admin')

@section('title', 'User Management')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">User Management / उपयोगकर्ता प्रबंधन</h1>
        <a href="{{ route('admin.users.create') }}" class="btn btn-success btn-sm">+ Provision Account</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Mobile</th><th>Role</th><th>Department</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php($role = $user->getRoleNames()->first())
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email ?? '—' }}</td>
                            <td>{{ $user->mobile ?? '—' }}</td>
                            <td>@foreach ($user->getRoleNames() as $r)<span class="badge text-bg-secondary">{{ $r }}</span> @endforeach</td>
                            <td>{{ $user->department?->name ?? '—' }}</td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-warning">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if (in_array($role, \App\Enums\Role::provisionableValues(), true))
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                @endif
                                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="d-inline"
                                      onsubmit="return confirm('Issue a new temporary password?');">
                                    @csrf
                                    <button class="btn btn-outline-warning btn-sm">Reset Password</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No users yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>
@endsection
