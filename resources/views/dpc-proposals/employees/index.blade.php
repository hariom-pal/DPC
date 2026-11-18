@extends('layouts.app')
@section('title', __('dpc.employee_details.index_title'))
@section('content')
    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('dpc.employee_details.index_title') }}</li>
        </ol>
    </nav>

    @include('partials.alerts')

    <h1 class="h4 mb-1">{{ __('dpc.employee_details.index_title') }}</h1>
    <p class="text-muted mb-3">{{ __('dpc.employee_details.index_lead') }}</p>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                <div class="text-muted small">{{ __('dpc.employee_details.officers_complete') }}</div>
                <div class="h4 mb-0 {{ $completeCount === $statuses->count() && $statuses->isNotEmpty() ? 'text-success' : '' }}" id="employees-complete-count">
                    {{ $completeCount }} / {{ $statuses->count() }}
                </div>
            </div></div>
        </div>
        @if ($phApplies)
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 small">
                <div class="text-muted">{{ __('dpc.employee_details.ph.quota_heading') }}</div>
                <div id="ph-quota">{{ __('dpc.employee_details.ph.quota', $phQuota) }}</div>
                <div class="text-muted">{{ __('dpc.employee_details.ph.quota_note') }}</div>
            </div></div>
        </div>
        @endif
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('dpc.employee_details.seniority_no') }}</th>
                        <th>{{ __('dpc.employee_details.employee_id') }}</th>
                        <th>{{ __('ui.name') }}</th>
                        <th>{{ __('dpc.employee_details.current_post') }}</th>
                        <th>{{ __('dpc.employee_details.completion') }}</th>
                        <th>{{ __('dpc.employee_details.rule.heading') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($statuses as $entry)
                        <tr data-employee-sn="{{ $entry['row']->sn }}">
                            <td>{{ $entry['row']->sn }}</td>
                            <td>{{ $entry['row']->employee_number ?? '—' }}</td>
                            <td>{{ $entry['row']->name }}</td>
                            <td>{{ $entry['row']->promotion_from_post ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $entry['status']['complete'] ? 'text-bg-success' : 'text-bg-warning' }}">
                                    {{ $entry['status']['complete'] ? __('dpc.employee_details.status.complete') : __('dpc.employee_details.status.incomplete') }}
                                </span>
                                @unless ($entry['status']['complete'])
                                    <div class="small text-muted">{{ $entry['status']['summary'] }}</div>
                                @endunless
                            </td>
                            <td>
                                @php
                                    $check = $ruleChecks[$entry['row']->id] ?? null;
                                @endphp
                                @if ($check !== null)
                                    @if (in_array($check['status'], ['meets', 'does_not_meet'], true))
                                        <x-fit-mark :fit="$check['status'] === 'meets'" data-rule-status="{{ $check['status'] }}" />
                                    @else
                                        <span class="badge {{ ['cir' => 'text-bg-warning', 'no_rule' => 'text-bg-secondary'][$check['status']] }}" data-rule-status="{{ $check['status'] }}">{{ __('dpc.employee_details.rule.status.'.$check['status']) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm {{ $canEdit ? 'btn-primary' : 'btn-outline-secondary' }}"
                                   href="{{ route('dpc-proposals.employees.show', [$proposal, $entry['row']]) }}">
                                    {{ $canEdit ? __('dpc.employee_details.complete_details') : __('dpc.employee_details.view_details') }}
                                </a>
                                {{-- The Administrative Head's verification of this officer's documents
                                     (rule 4.4, MoM of 16 November 2026), beside View Details. --}}
                                @php
                                    $docTotal = $entry['row']->currentDocuments->count();
                                    $docVerified = $entry['row']->currentDocuments->whereNotNull('ah_verified_at')->count();
                                @endphp
                                @if ($docTotal > 0)
                                    <div class="small mt-1" data-verification-progress="{{ $entry['row']->sn }}">
                                        @if ($docVerified === $docTotal)
                                            <span class="badge text-bg-success">{{ __('dpc.document_verification.completed') }}</span>
                                        @else
                                            <span class="badge text-bg-warning">{{ __('dpc.document_verification.progress', ['verified' => $docVerified, 'total' => $docTotal]) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                {{ __('dpc.employee_details.no_officers') }}
                                <a href="{{ route('dpc-proposals.seniority-list', $proposal) }}">{{ __('ui.seniority_list') }}</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
