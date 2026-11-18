@extends('layouts.admin')
@section('title', __('admin.integrity_options.title'))
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('admin.integrity_options.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.integrity_options.intro') }}</p>
        </div>
        <a href="{{ route('admin.integrity-options.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'integrity-options'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.code') }}</th>
                        <th>{{ __('ui.name_en') }}</th>
                        <th>{{ __('ui.hi') }}</th>
                        <th>{{ __('admin.integrity_options.fit_column') }}</th>
                        <th class="text-end">{{ __('admin.integrity_options.order_column') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reasons as $reason)
                        <tr>
                            <td><code>{{ $reason->code }}</code></td>
                            <td>{{ $reason->name_en }}</td>
                            <td>{{ $reason->name_hi }}</td>
                            <td>
                                @if ($reason->needs_departmental_view)
                                    <span class="badge text-bg-warning" data-integrity-fit="view">{{ __('admin.integrity_options.by_view') }}</span>
                                @else
                                    <x-fit-mark :fit="(bool) $reason->fit" data-integrity-fit="{{ $reason->fit ? 'fit' : 'unfit' }}" />
                                @endif
                            </td>
                            <td class="text-end">{{ $reason->sort_order }}</td>
                            <td><x-status-badge :status="$reason->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.integrity-options.edit', $reason) }}"
                                   class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.integrity-options.destroy', $reason) }}"
                                      class="d-inline"
                                      data-confirm="{{ __('admin.integrity_options.withdraw_confirm') }}">
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

    <div class="mt-3">{{ $reasons->links() }}</div>
@endsection
