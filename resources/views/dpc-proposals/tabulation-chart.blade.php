@extends('layouts.app')
@section('title', __('dpc.tabulation.title'))
{{-- The whole width of the screen, so the chart shows at once (2026-11-18). --}}
@section('main-container', 'container-fluid px-3')
@section('content')
    {{-- MoM of 16 November 2026, point 9: the lists as uploaded, and a Tabulation Chart
         that sets the uploaded (CSV) data apart from what was added on the portal. --}}
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('dpc.tabulation.title') }}</h1>
            <p class="text-muted mb-0">{{ $proposal->citation() }}</p>
        </div>
        <a href="{{ auth()->user()?->can('view', $proposal) ? route('dpc-proposals.show', $proposal) : route('sitting.show', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.tabulation.back') }}</a>
    </div>

    <div class="card border-0 shadow-sm mb-3" id="original-lists">
        <div class="card-body">
            <h2 class="h6">{{ __('dpc.tabulation.originals') }}</h2>
            <ul class="list-unstyled mb-0">
                @foreach ($originals as $template => $batch)
                    <li class="mb-1" data-original="{{ $template }}">
                        <strong>{{ \App\Domain\Import\Enums\ImportTemplate::from($template)->label() }}:</strong>
                        @if ($batch)
                            <a href="{{ route('dpc-proposals.originals.download', [$proposal, $batch]) }}">{{ __('dpc.tabulation.download', ['file' => $batch->original_filename]) }}</a>
                            <span class="small text-muted">{{ __('dpc.tabulation.uploaded_on', ['date' => $batch->committed_at?->format('d-m-Y H:i') ?? '—']) }}</span>
                        @else
                            <span class="text-muted">{{ __('dpc.tabulation.not_uploaded') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="small d-flex flex-wrap align-items-center gap-2">
            <span class="badge text-bg-light border">{{ __('dpc.tabulation.legend_csv') }}</span>
            <span class="badge text-bg-info">{{ __('dpc.tabulation.legend_portal') }}</span>
            <span class="d-inline-flex align-items-center gap-1"><x-fit-mark :fit="true" /><x-fit-mark :fit="false" /></span>
            <span class="text-muted">{{ __('dpc.tabulation.legend_tribal') }} · {{ __('dpc.tabulation.legend_pf') }} · {{ __('dpc.tabulation.legend_replaced') }}</span>
        </div>
        <a href="{{ route('dpc-proposals.tabulation-chart.download', $proposal) }}" class="btn btn-success btn-sm" id="tabulation-download">{{ __('dpc.tabulation.download_chart') }}</a>
    </div>

    {{-- 2026-11-18: CR 1 to CR 5 (and PF 1 to PF 5) across the header with each one's
         year beneath, every cell on one line; Fit and Unfit by colour alone. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" id="tabulation-chart">
                <thead>
                    <tr>
                        <th colspan="8" class="table-light text-center">{{ __('dpc.tabulation.group_csv') }}</th>
                        <th colspan="{{ count($crYears) + count($propertyYears) + 5 }}" class="table-info text-center">{{ __('dpc.tabulation.group_portal') }}</th>
                    </tr>
                    <tr>
                        <th class="table-light sticky-col sticky-sn">{{ __('dpc.employee_details.seniority_no') }}</th>
                        <th class="table-light">{{ __('dpc.employee_details.employee_id') }}</th>
                        <th class="table-light sticky-col sticky-name text-start">{{ __('ui.name') }}</th>
                        <th class="table-light">{{ __('dpc.tabulation.dob') }}</th>
                        <th class="table-light">{{ __('dpc.tabulation.joining') }}</th>
                        <th class="table-light">{{ __('ui.category') }}</th>
                        <th class="table-light">{{ __('dpc.employee_details.current_post') }}</th>
                        <th class="table-light">{{ __('dpc.tabulation.qualification') }}</th>
                        @foreach ($crYears as $year)
                            <th class="table-info" data-cr-column="{{ $loop->iteration }}">{{ __('dpc.tabulation.cr_n', ['n' => $loop->iteration]) }}<span class="tab-year">{{ $year }}</span></th>
                        @endforeach
                        @foreach ($propertyYears as $year)
                            <th class="table-info" title="{{ __('dpc.tabulation.property_full', ['n' => $loop->iteration]) }}">{{ __('dpc.tabulation.property_n', ['n' => $loop->iteration]) }}<span class="tab-year">{{ $year }}</span></th>
                        @endforeach
                        <th class="table-info">{{ __('dpc.tabulation.service_orders') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.ph') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.enquiry') }}</th>
                        <th class="table-info"><x-fit-mark :fit="false" /> {{ __('dpc.tabulation.unfit') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.documents') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proposal->evaluationRows as $row)
                        <tr data-tabulation-sn="{{ $row->sn }}">
                            <td class="table-light sticky-col sticky-sn">{{ $row->sn }}</td>
                            <td class="table-light">{{ $row->employee_number ?? '—' }}</td>
                            <td class="table-light sticky-col sticky-name text-start">{{ $row->name }}</td>
                            <td class="table-light">{{ $row->dob?->format('d-m-Y') ?? '—' }}</td>
                            <td class="table-light">{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</td>
                            <td class="table-light">{{ $row->reservationCategory?->code ?? '—' }}</td>
                            <td class="table-light">{{ $row->promotion_from_post ?? '—' }}</td>
                            <td class="table-light">{{ $row->qualification ?? '—' }}</td>
                            @foreach ($crYears as $year)
                                @php
                                    $cr = $row->crGrades->firstWhere('year', $year);
                                    $crFit = ($cr !== null && $cr->put_up !== false && $cr->has_grading !== false) ? $cr->integrityFit() : null;
                                @endphp
                                <td class="{{ $crFit === null ? '' : ($crFit ? 'cell-fit' : 'cell-unfit') }}" @if ($cr?->integrityOption) title="{{ $cr->integrityOption->label() }}" @endif @if ($crFit !== null) data-fit="{{ $crFit ? 'fit' : 'unfit' }}" @endif>
                                    @if ($cr === null)
                                        —
                                    @elseif ($cr->put_up === false || $cr->has_grading === false)
                                        <span class="{{ $cr->put_up === false ? 'text-danger' : '' }}">{{ $cr->put_up === false ? __('dpc.tabulation.missing') : __('dpc.tabulation.no_grading') }}</span>
                                        {{-- The older year counted in its place, as the calculation takes it (2026-11-18). --}}
                                        @if (array_key_exists($year, $replacements[$row->id] ?? []))
                                            @php
                                                $instead = $replacements[$row->id][$year];
                                                $insteadCr = $instead === null ? null : $row->crGrades->firstWhere('year', $instead);
                                            @endphp
                                            <div class="tab-replaced" data-replaced-year="{{ $year }}" data-replaced-by="{{ $instead ?? 'none' }}" @if ($insteadCr?->integrityOption) title="{{ $insteadCr->integrityOption->label() }}" @endif>
                                                @if ($instead === null)
                                                    {{ __('dpc.tabulation.no_older_cr') }}
                                                @else
                                                    {{ __('dpc.tabulation.replaced_by', ['year' => $instead]) }} <strong>{{ $insteadCr?->grade ?? '—' }}</strong>
                                                    @if (($insteadFit = $insteadCr?->integrityFit()) !== null)<x-fit-mark :fit="$insteadFit" />@endif
                                                @endif
                                            </div>
                                        @endif
                                    @else
                                        <strong>{{ $cr->grade ?? '—' }}</strong>
                                        @if ($cr->tribal_area === true)<span class="badge text-bg-info ms-1" title="{{ __('dpc.employee_details.cr.tribal_yes') }}">{{ __('dpc.tabulation.tribal_short') }}</span>@endif
                                    @endif
                                </td>
                            @endforeach
                            @foreach ($propertyYears as $year)
                                @php
                                    $property = $row->propertyFlags->firstWhere('year', $year);
                                @endphp
                                <td>
                                    @if ($property === null)
                                        —
                                    @elseif ($property->submitted_on)
                                        {{ $property->submitted_on->format('d-m-Y') }}@if ($property->isLate()) <span class="badge text-bg-warning">{{ __('dpc.tabulation.late') }}</span>@endif
                                    @else
                                        <span class="text-danger">{{ __('dpc.tabulation.missing') }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                @if ($row->dob_order_number || $row->joining_order_number)
                                    {{ __('dpc.tabulation.birth') }}: {{ $row->dob_order_number ?? '—' }} · {{ __('dpc.tabulation.joining_letter') }}: {{ $row->joining_order_number ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $row->is_ph === null ? '—' : ($row->is_ph ? __('ui.yes') : __('ui.no')) }}</td>
                            <td>
                                {{ __('dpc.tabulation.case') }}: {{ $row->enquiry_ongoing === null ? '—' : ($row->enquiry_ongoing ? __('ui.yes') : __('ui.no')) }} ·
                                {{ __('dpc.tabulation.punishment') }}: {{ $row->punishment_effective === null ? '—' : ($row->punishment_effective ? __('ui.yes') : __('ui.no')) }}
                            </td>
                            <td class="tab-grounds">
                                @forelse ($row->unfitReasons() as $reason)
                                    <div class="text-danger">{{ $reason }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>{{ $row->currentDocuments->count() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="30" class="text-center text-muted py-3">{{ __('dpc.employee_details.no_officers') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
