@extends('layouts.admin')

@section('title', __('admin.departments.index.departments'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">{{ __('admin.departments.index.department_master') }}</h1>
            <p class="text-muted small mb-0">
                {{ __('admin.departments.index.the_list_offered_on_the_registration_screen_the') }}
            </p>
        </div>
        <a href="{{ route('admin.departments.create') }}" class="btn btn-success btn-sm">{{ __('admin.departments.index.new_department') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'departments'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.code') }}</th>
                        <th>{{ __('ui.name') }}</th>
                        <th>{{ __('ui.district') }}</th>
                        <th>{{ __('admin.departments.index.contact_email') }}</th>
                        <th class="text-center">{{ __('admin.departments.index.users') }}</th>
                        <th class="text-center">{{ __('admin.departments.index.posts') }}</th>
                        <th class="text-center">{{ __('ui.dpcs') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
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
                            <td class="text-center">{{ $department->posts_count }}</td>
                            <td class="text-center">{{ $department->dpc_proposals_count }}</td>
                            <td><span class="badge text-bg-light border">{{ $department->status->label() }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('admin.departments.vacancy-master.index', $department) }}" class="btn btn-outline-secondary btn-sm">{{ __('vacancymaster.open_vacancy_master') }}</a>
                                <a href="{{ route('admin.departments.edit', $department) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.departments.destroy', $department) }}" class="d-inline"
                                      data-confirm="{{ __('admin.departments.index.delete_this_department') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">{{ __('admin.departments.index.no_departments_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $departments->links() }}</div>
@endsection
