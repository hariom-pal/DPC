@extends('layouts.app')

@section('title', __('ui.reports'))

@php
    // Bars are plain divs sized by percentage — no charting library, no CDN and no
    // build step, which is the same constraint Bootstrap is vendored under. A
    // dependency would buy animation and cost a asset pipeline this project
    // deliberately does not have.
    $maxPipeline = max(1, collect($pipeline)->max('count') ?? 1);
    $maxYear = max(1, collect($byYear)->max('count') ?? 1);
@endphp

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-1">{{ __('ui.reports') }}</h1>
                    <p class="text-muted small mb-0">
                        @if ($scopedToDepartment)
{{ __('reportsui.index.scoped_lead', ['department' => $departmentName]) }}
                        @else
{{ __('reportsui.index.all_departments') }}
                        @endif
{{ __('reportsui.index.counted_from_records') }}
                    </p>
                </div>
                <a href="{{ route('reports.export') }}" class="btn btn-outline-secondary btn-sm">{{ __('reportsui.index.export_pipeline_csv') }}</a>
            </div>

            {{-- Outcomes first: it is the question anybody actually opens a report
                 to answer. --}}
            <div class="row g-3 mb-3">
                @foreach ([
                    ['Promoted', $outcomes['promoted'], 'On an approved main list', 'success'],
                    ['On a waiting list', $outcomes['waiting'], 'Approved, awaiting a vacancy', 'secondary'],
                    ['Held (CIR)', $outcomes['held'], 'Decision reserved — resolved by circulation', 'warning'],
                    ['Not placed', $outcomes['not_eligible'], 'Considered but on neither list', 'light'],
                ] as [$label, $value, $hint, $tone])
                    <div class="col-sm-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <span class="small text-muted">{{ $label }}</span>
                                    <span class="h4 mb-0">{{ number_format($value) }}</span>
                                </div>
                                <span class="badge text-bg-{{ $tone }} mt-2">{{ $hint }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6 mb-1">{{ __('reportsui.index.where_the_dpcs_are') }}</h2>
                    <p class="small text-muted">
                        {{ __('reportsui.index.the_stages_of_the_process_in_the_order_the_proce') }}
                    </p>

                    @foreach ($pipeline as $stage)
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small">
                                <span>
                                    {{ $stage['stage'] }}
                                    <span class="text-muted">· {{ $stage['reference'] }}</span>
                                </span>
                                <span class="fw-semibold">{{ number_format($stage['count']) }}</span>
                            </div>
                            <div class="progress" role="img"
                                 aria-label="{{ $stage['stage'] }}: {{ $stage['count'] }}"
                                 style="height:.5rem">
                                <div class="progress-bar" style="width: {{ round($stage['count'] / $maxPipeline * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6 mb-1">{{ __('reportsui.index.dpcs_by_year') }}</h2>
                            <p class="small text-muted">
                                {{ __('reportsui.index.by_the_date_the_department_filed_the_proposal_fo') }}
                            </p>

                            @forelse ($byYear as $row)
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small">
                                        <span>{{ $row['year'] }}</span>
                                        <span class="fw-semibold">{{ number_format($row['count']) }}</span>
                                    </div>
                                    <div class="progress" role="img"
                                         aria-label="{{ $row['year'] }}: {{ $row['count'] }}"
                                         style="height:.5rem">
                                        <div class="progress-bar bg-secondary"
                                             style="width: {{ round($row['count'] / $maxYear * 100) }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="small text-muted mb-0">{{ __('reportsui.index.no_dpc_has_been_raised_yet') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h6 mb-1">{{ __('reportsui.index.corrections_and_circulations') }}</h2>
                            <p class="small text-muted">
                                {{ __('reportsui.index.the_two_flows_that_act_on_a_dpc_after_it_has_con') }}
                            </p>
                            <ul class="list-group list-group-flush small">
                                <li class="list-group-item d-flex justify-content-between px-0">
                                    {!! __('reportsui.index.reviews_raised') !!}
                                    <span class="fw-semibold">{{ number_format($variants['reviews']) }}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0">
                                    {!! __('reportsui.index.circulations_raised') !!}
                                    <span class="fw-semibold">{{ number_format($variants['circulations']) }}</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between px-0">
                                    {{-- The signature is the operative act (AQ-17): an
                                         unsigned note releases no promotion. --}}
                                    <span>{{ __('reportsui.index.circulation_notes_signed') }}</span>
                                    <span class="fw-semibold">{{ number_format($variants['circulations_signed']) }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            @if ($byDepartment !== [])
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-1">{{ __('reportsui.index.by_department') }}</h2>
                        <p class="small text-muted">
                            {{ __('reportsui.index.departments_with_no_dpc_yet_are_omitted_rather_t') }}
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('ui.department') }}</th>
                                        <th class="text-end">{{ __('ui.dpcs') }}</th>
                                        <th class="text-end">{{ __('reportsui.index.forwarded') }}</th>
                                        <th class="text-end">{{ __('reportsui.index.results_approved') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($byDepartment as $row)
                                        <tr>
                                            <td>
                                                {{ $row['department'] }}
                                                <span class="text-muted small">({{ $row['code'] }})</span>
                                            </td>
                                            <td class="text-end">{{ number_format($row['total']) }}</td>
                                            <td class="text-end">{{ number_format($row['forwarded']) }}</td>
                                            <td class="text-end">{{ number_format($row['results']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
