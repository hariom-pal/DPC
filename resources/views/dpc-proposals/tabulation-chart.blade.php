@extends('layouts.app')
@section('title', __('dpc.tabulation.title'))
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

    <div class="small mb-2">
        <span class="badge text-bg-light border">{{ __('dpc.tabulation.legend_csv') }}</span>
        <span class="badge text-bg-info">{{ __('dpc.tabulation.legend_portal') }}</span>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle small mb-0" id="tabulation-chart">
                <thead>
                    <tr>
                        <th colspan="8" class="table-light text-center">{{ __('dpc.tabulation.group_csv') }}</th>
                        <th colspan="{{ count($crYears) + count($propertyYears) + 5 }}" class="table-info text-center">{{ __('dpc.tabulation.group_portal') }}</th>
                    </tr>
                    <tr>
                        <th class="table-light">{{ __('dpc.employee_details.seniority_no') }}</th>
                        <th class="table-light">{{ __('dpc.employee_details.employee_id') }}</th>
                        <th class="table-light">{{ __('ui.name') }}</th>
                        <th class="table-light">{{ __('dpc.tabulation.dob') }}</th>
                        <th class="table-light">{{ __('dpc.tabulation.joining') }}</th>
                        <th class="table-light">{{ __('ui.category') }}</th>
                        <th class="table-light">{{ __('dpc.employee_details.current_post') }}</th>
                        <th class="table-light">{{ __('dpc.tabulation.qualification') }}</th>
                        @foreach ($crYears as $year)
                            <th class="table-info">{{ __('dpc.tabulation.cr_year', ['year' => $year]) }}</th>
                        @endforeach
                        @foreach ($propertyYears as $year)
                            <th class="table-info">{{ __('dpc.tabulation.property_year', ['year' => $year]) }}</th>
                        @endforeach
                        <th class="table-info">{{ __('dpc.tabulation.service_orders') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.ph') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.enquiry') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.unfit') }}</th>
                        <th class="table-info">{{ __('dpc.tabulation.documents') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proposal->evaluationRows as $row)
                        <tr data-tabulation-sn="{{ $row->sn }}">
                            <td class="table-light">{{ $row->sn }}</td>
                            <td class="table-light">{{ $row->employee_number ?? '—' }}</td>
                            <td class="table-light">{{ $row->name }}</td>
                            <td class="table-light">{{ $row->dob?->format('d-m-Y') ?? '—' }}</td>
                            <td class="table-light">{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</td>
                            <td class="table-light">{{ $row->reservationCategory?->code ?? '—' }}</td>
                            <td class="table-light">{{ $row->promotion_from_post ?? '—' }}</td>
                            <td class="table-light">{{ $row->qualification ?? '—' }}</td>
                            @foreach ($crYears as $year)
                                @php
                                    $cr = $row->crGrades->firstWhere('year', $year);
                                @endphp
                                <td>
                                    @if ($cr === null)
                                        —
                                    @elseif ($cr->put_up === false)
                                        <span class="text-danger">{{ __('dpc.tabulation.missing') }}</span>
                                    @elseif ($cr->has_grading === false)
                                        {{ __('dpc.tabulation.no_grading') }}
                                    @else
                                        <strong>{{ $cr->grade ?? '—' }}</strong>
                                        @if ($cr->integrityOption)<div class="text-muted">{{ $cr->integrityOption->label() }}</div>@endif
                                        @if ($cr->tribal_area === true)<span class="badge text-bg-info">{{ __('dpc.employee_details.cr.tribal_yes') }}</span>@endif
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
                                    {{ __('dpc.tabulation.birth') }}: {{ $row->dob_order_number ?? '—' }}<br>
                                    {{ __('dpc.tabulation.joining_letter') }}: {{ $row->joining_order_number ?? '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $row->is_ph === null ? '—' : ($row->is_ph ? __('ui.yes') : __('ui.no')) }}</td>
                            <td>
                                {{ __('dpc.tabulation.case') }}: {{ $row->enquiry_ongoing === null ? '—' : ($row->enquiry_ongoing ? __('ui.yes') : __('ui.no')) }}<br>
                                {{ __('dpc.tabulation.punishment') }}: {{ $row->punishment_effective === null ? '—' : ($row->punishment_effective ? __('ui.yes') : __('ui.no')) }}
                            </td>
                            <td>
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
