@extends('layouts.admin')

@section('title', __('ui.class_master'))

@section('content')
    {{-- Class Master (change item 1). Dedicated views rather than the shared
         lookup partial, because this master carries a display order and a code
         that is fixed by the service rules — neither of which the shared partial
         knows about. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('ui.class_master') }}</h1>
        @if ($canAddMore)
            <a href="{{ route('admin.service-classes.create') }}" class="btn btn-success btn-sm">{{ __('admin.service_classes.index.new_class') }}</a>
        @endif
    </div>

    <div class="alert alert-secondary small">
{!! __('admin.service_classes.index.lead') !!}
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.code') }}</th><th>{{ __('ui.name_en') }}</th><th>{{ __('ui.hi') }}</th><th>{{ __('ui.order') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($classes as $class)
                        <tr>
                            <td><code>{{ $class->code }}</code></td>
                            <td><a href="{{ route('admin.service-classes.promotion-rules.index', $class) }}" class="fw-semibold">{{ $class->name_en }}</a></td>
                            <td>{{ $class->name_hi ?? '—' }}</td>
                            <td>{{ $class->sort_order }}</td>
                            <td><x-status-badge :status="$class->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.service-classes.promotion-rules.index', $class) }}" class="btn btn-outline-success btn-sm">{{ __('admin.promotion_rules.button') }}</a>
                                <a href="{{ route('admin.service-classes.edit', $class) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.service-classes.destroy', $class) }}" class="d-inline"
                                      data-confirm="{{ __('admin.service_classes.index.withdraw_this_class_from_the_master_proposals_th') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.withdraw') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">{{ __('admin.service_classes.index.no_classes_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $classes->links() }}</div>
@endsection
