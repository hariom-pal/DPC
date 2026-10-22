@extends('layouts.admin')
@section('title', __('admin.sealed_cover_reasons.title'))
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('admin.sealed_cover_reasons.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.sealed_cover_reasons.intro') }}</p>
        </div>
        <a href="{{ route('admin.sealed-cover-reasons.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'sealed-cover-reasons'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.code') }}</th>
                        <th>{{ __('ui.name_en') }}</th>
                        <th>{{ __('ui.hi') }}</th>
                        <th>{{ __('admin.sealed_cover_reasons.effect_column') }}</th>
                        <th>{{ __('admin.sealed_cover_reasons.document_column') }}</th>
                        <th class="text-end">{{ __('admin.sealed_cover_reasons.order_column') }}</th>
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
                                <span class="badge {{ $reason->sealsTheCover() ? 'text-bg-danger' : 'text-bg-secondary' }}">{{ __('admin.sealed_cover_reasons.effects.'.$reason->effect) }}</span>
                            </td>
                            <td>{{ $reason->requires_document ? __('ui.yes') : __('ui.no') }}</td>
                            <td class="text-end">{{ $reason->sort_order }}</td>
                            <td><x-status-badge :status="$reason->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.sealed-cover-reasons.edit', $reason) }}"
                                   class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.sealed-cover-reasons.destroy', $reason) }}"
                                      class="d-inline"
                                      data-confirm="{{ __('admin.sealed_cover_reasons.withdraw_confirm') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">{{ __('ui.no_records_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $reasons->links() }}</div>
@endsection
