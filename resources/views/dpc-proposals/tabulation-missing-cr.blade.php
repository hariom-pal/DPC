@extends('layouts.app')
@section('title', __('dpc.tabulation.missing_cr.title', ['name' => $row->name]))
@section('content')
    {{-- 2026-11-22: opened in its own tab from the Tabulation Chart's "View missing CR". --}}
    <div class="mb-3">
        <h1 class="h4 mb-1">{{ __('dpc.tabulation.missing_cr.title', ['name' => $row->name]) }}</h1>
        <p class="text-muted mb-0">{{ $proposal->citation() }} · {{ __('dpc.employee_details.seniority_no') }} {{ $row->sn }}@if ($row->employee_number) · {{ __('dpc.employee_details.employee_id') }} {{ $row->employee_number }}@endif</p>
    </div>
    <p class="small">{{ __('dpc.tabulation.missing_cr.intro') }}</p>

    @if ($missing === [])
        <div class="alert alert-success" id="missing-cr-none">{{ __('dpc.tabulation.missing_cr.none') }}</div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0" id="missing-cr">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('dpc.tabulation.missing_cr.year') }}</th>
                            <th>{{ __('dpc.tabulation.missing_cr.state') }}</th>
                            <th>{{ __('dpc.tabulation.missing_cr.reason') }}</th>
                            <th>{{ __('dpc.tabulation.missing_cr.remarks') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($missing as $line)
                            <tr data-missing-year="{{ $line['year'] }}" data-state="{{ $line['state'] }}">
                                <td>{{ $line['year'] }}</td>
                                <td>{{ __('dpc.tabulation.missing_cr.states.'.$line['state']) }}</td>
                                <td>{{ $line['reason'] ?? __('dpc.tabulation.missing_cr.no_reason') }}</td>
                                <td>{{ $line['remarks'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
