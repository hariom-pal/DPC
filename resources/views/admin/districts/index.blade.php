@extends('layouts.admin')

@section('title', __('admin.districts.index.districts_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.districts.index.districts') }}</h1>
        <a href="{{ route('admin.districts.create') }}" class="btn btn-success btn-sm">{{ __('admin.districts.index.new_district') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'districts'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.name_en') }}</th>
                        <th>{{ __('ui.hi') }}</th>
                        <th>{{ __('admin.districts.index.lgd_code') }}</th>
                        <th>{{ __('admin.districts.index.state_lgd') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
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
                                <a href="{{ route('admin.districts.edit', $district) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.districts.destroy', $district) }}" class="d-inline"
                                      data-confirm="{{ __('admin.districts.index.delete_this_district') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">{{ __('admin.districts.index.no_districts_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $districts->links() }}</div>
@endsection
