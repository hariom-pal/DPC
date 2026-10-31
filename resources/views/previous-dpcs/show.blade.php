@extends('layouts.app')
@section('title', __('previous_dpc.title'))
@section('content')
    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('previous-dpcs.index') }}">{{ __('previous_dpc.title') }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $record->reference }}</li>
        </ol>
    </nav>
    @include('partials.alerts')
    <h1 class="h4 mb-3">{{ $record->reference }}</h1>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-sm-3">{{ __('previous_dpc.fields.department_id') }}</dt><dd class="col-sm-9">{{ $record->department?->name }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.fields.promotion_from_post_id') }}</dt><dd class="col-sm-9">{{ $record->promotionFromPost?->name ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.fields.promotion_to_post_id') }}</dt><dd class="col-sm-9">{{ $record->promotionToPost?->name }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.fields.dpc_year') }}</dt><dd class="col-sm-9">{{ $record->dpc_year }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.fields.held_on') }}</dt><dd class="col-sm-9">{{ $record->held_on->format('d-m-Y') }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.fields.order_number') }}</dt><dd class="col-sm-9">{{ $record->order_number ?? '—' }} {{ $record->order_date?->format('d-m-Y') }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.fields.eligible_count') }}</dt><dd class="col-sm-9">{{ $record->eligible_count }}</dd>
                <dt class="col-sm-3">{{ __('previous_dpc.recorded_by') }}</dt><dd class="col-sm-9">{{ $record->recorder?->name ?? '—' }} · {{ $record->created_at?->format('d-m-Y H:i') }}</dd>
                @foreach (\App\Domain\PreviousDpc\Models\PreviousDpcRecord::FILES as $file)
                    <dt class="col-sm-3">{{ __('previous_dpc.fields.'.$file) }}</dt>
                    <dd class="col-sm-9">
                        @if ($record->hasFile($file))
                            <a href="{{ route('previous-dpcs.file', [$record, $file]) }}">{{ $record->getAttribute($file.'_original_name') }}</a>
                        @else
                            —
                        @endif
                    </dd>
                @endforeach
            </dl>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h2 class="h6">{{ __('previous_dpc.fields.promoted_officers') }} ({{ count($record->promoted_officers) }})</h2>
            <table class="table table-sm small mb-0" id="previous-dpc-officers">
                <thead><tr><th>#</th><th>{{ __('dpc.employee_details.employee_id') }}</th><th>{{ __('ui.name') }}</th></tr></thead>
                <tbody>
                    @foreach ($record->promoted_officers as $index => $officer)
                        <tr><td>{{ $index + 1 }}</td><td>{{ $officer['employee_number'] ?? '—' }}</td><td>{{ $officer['name'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
