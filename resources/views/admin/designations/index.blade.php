@extends('layouts.admin')

@section('title', __('admin.designations.index.designations_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.designations.index.designations') }}</h1>
        <a href="{{ route('admin.designations.create') }}" class="btn btn-success btn-sm">{{ __('admin.designations.index.new_designation') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'designations'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.name') }}</th><th>{{ __('ui.code') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($designations as $designation)
                        <tr>
                            <td>{{ $designation->name }}</td>
                            <td>{{ $designation->code ?? '—' }}</td>
                            <td><x-status-badge :status="$designation->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.designations.edit', $designation) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.designations.destroy', $designation) }}" class="d-inline"
                                      data-confirm="{{ __('admin.designations.index.delete_this_designation') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">{{ __('admin.designations.index.no_designations_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $designations->links() }}</div>
@endsection
