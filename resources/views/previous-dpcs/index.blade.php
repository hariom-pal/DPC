@extends('layouts.app')
@section('title', __('previous_dpc.title'))
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">{{ __('previous_dpc.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('previous_dpc.lead') }}</p>
        </div>
        @if ($canRecord)
            <a href="{{ route('previous-dpcs.create') }}" class="btn btn-success btn-sm">{{ __('previous_dpc.new') }}</a>
        @endif
    </div>
    @include('partials.alerts')
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle small" id="previous-dpcs">
                <thead><tr>
                    <th>{{ __('previous_dpc.fields.department_id') }}</th><th>{{ __('previous_dpc.fields.promotion_to_post_id') }}</th>
                    <th>{{ __('previous_dpc.fields.dpc_year') }}</th><th>{{ __('previous_dpc.fields.held_on') }}</th>
                    <th>{{ __('previous_dpc.fields.reference') }}</th><th>{{ __('previous_dpc.fields.eligible_count') }}</th><th></th>
                </tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>{{ $record->department?->name }}</td>
                            <td>{{ $record->promotionToPost?->name }}</td>
                            <td>{{ $record->dpc_year }}</td>
                            <td>{{ $record->held_on->format('d-m-Y') }}</td>
                            <td>{{ $record->reference }}</td>
                            <td>{{ $record->eligible_count }}</td>
                            <td><a href="{{ route('previous-dpcs.show', $record) }}">{{ __('previous_dpc.open') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">{{ __('previous_dpc.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $records->links() }}</div>
@endsection
