@extends('layouts.admin')

@section('title', __('ui.user_management'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.users.index.user_management') }}</h1>
        <a href="{{ route('admin.users.create') }}" class="btn btn-success btn-sm">{{ __('admin.users.index.provision_account') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.users._issued-password')

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.name') }}</th><th>{{ __('ui.email') }}</th><th>{{ __('ui.mobile') }}</th><th>{{ __('ui.role') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php($role = $user->getRoleNames()->first())
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email ?? '—' }}</td>
                            <td>{{ $user->mobile ?? '—' }}</td>
                            <td>@foreach ($user->getRoleNames() as $r)<span class="badge text-bg-secondary">{{ $r }}</span> @endforeach</td>
                            {{-- Read off the live assignment since change item 2: the
                                 officer's department is a dated posting, not a column. --}}
                            <td>
                                {{ $user->department?->name ?? '—' }}
                                @if ($user->activeAssignment !== null)
                                    {!! __('admin.users.index.since', ['date' => e($user->activeAssignment->effective_from->format('d-m-Y'))]) !!}
                                @endif
                            </td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge text-bg-success">{{ __('ui.active') }}</span>
                                @else
                                    <span class="badge text-bg-warning">{{ __('ui.inactive') }}</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if (in_array($role, \App\Enums\Role::provisionableValues(), true))
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                @endif
                                <a href="{{ route('admin.users.assignments.index', $user) }}" class="btn btn-outline-info btn-sm">{{ __('admin.users.index.assignments') }}</a>
                                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="d-inline"
                                      data-confirm="{{ __('admin.users.index.issue_a_new_temporary_password') }}">
                                    @csrf
                                    <button class="btn btn-outline-warning btn-sm">{{ __('admin.users.index.reset_password') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('admin.users.index.no_users_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>
@endsection
