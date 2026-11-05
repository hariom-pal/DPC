@extends('layouts.admin')
@section('title', __('admin.ph_types.title'))
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('admin.ph_types.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.ph_types.intro') }}</p>
        </div>
        <a href="{{ route('admin.ph-types.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'ph-types'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.code') }}</th>
                        <th>{{ __('ui.name_en') }}</th>
                        <th>{{ __('ui.hi') }}</th>
                        <th>{{ __('admin.ph_types.category') }}</th>
                        <th class="text-end">{{ __('admin.ph_types.order') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($types as $type)
                        <tr>
                            <td><code>{{ $type->code }}</code></td>
                            <td>{{ $type->name_en }}</td>
                            <td>{{ $type->name_hi }}</td>
                            <td class="small">{{ $type->category ? __('admin.ph_types.categories.'.$type->category) : '—' }}</td>
                            <td class="text-end">{{ $type->sort_order }}</td>
                            <td><x-status-badge :status="$type->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.ph-types.edit', $type) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.ph-types.destroy', $type) }}" class="d-inline"
                                      data-confirm="{{ __('admin.ph_types.delete_confirm') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('ui.no_records_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $types->links() }}</div>
@endsection
