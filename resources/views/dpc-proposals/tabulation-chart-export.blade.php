{{-- The Tabulation Chart workbook (2026-11-18). Fit and Unfit are the cell colour. --}}
<table>
    <thead>
        <tr>
            <th colspan="{{ 8 + count($crYears) + count($propertyYears) + 5 }}" style="font-weight: bold; font-size: 13px;">{{ __('dpc.tabulation.title') }} — {{ $proposal->citation() }}</th>
        </tr>
        <tr>
            <th colspan="8" style="font-weight: bold; background-color: #E9ECEF; text-align: center;">{{ __('dpc.tabulation.group_csv') }}</th>
            <th colspan="{{ count($crYears) + count($propertyYears) + 5 }}" style="font-weight: bold; background-color: #CFF4FC; text-align: center;">{{ __('dpc.tabulation.group_portal') }}</th>
        </tr>
        <tr>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.employee_details.seniority_no') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.employee_details.employee_id') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('ui.name') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.tabulation.dob') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.tabulation.joining') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('ui.category') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.employee_details.current_post') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.tabulation.qualification') }}</th>
            @foreach ($crYears as $year)
                <th style="font-weight: bold; background-color: #CFF4FC; text-align: center;">{{ __('dpc.tabulation.cr_n', ['n' => $loop->iteration]) }}</th>
            @endforeach
            @foreach ($propertyYears as $year)
                <th style="font-weight: bold; background-color: #CFF4FC; text-align: center;">{{ __('dpc.tabulation.property_full', ['n' => $loop->iteration]) }}</th>
            @endforeach
            <th rowspan="2" style="font-weight: bold; background-color: #CFF4FC;">{{ __('dpc.tabulation.service_orders') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #CFF4FC;">{{ __('dpc.tabulation.ph') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #CFF4FC;">{{ __('dpc.tabulation.enquiry') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #F8D7DA;">{{ __('dpc.tabulation.unfit') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #CFF4FC;">{{ __('dpc.tabulation.documents') }}</th>
        </tr>
        <tr>
            @foreach ($crYears as $year)
                <th style="background-color: #CFF4FC; text-align: center;">{{ $year }}</th>
            @endforeach
            @foreach ($propertyYears as $year)
                <th style="background-color: #CFF4FC; text-align: center;">{{ $year }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($proposal->evaluationRows as $row)
            <tr>
                <td>{{ $row->sn }}</td>
                <td>{{ $row->employee_number ?? '—' }}</td>
                <td>{{ $row->name }}</td>
                <td>{{ $row->dob?->format('d-m-Y') ?? '—' }}</td>
                <td>{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</td>
                <td>{{ $row->reservationCategory?->code ?? '—' }}</td>
                <td>{{ $row->promotion_from_post ?? '—' }}</td>
                <td>{{ $row->qualification ?? '—' }}</td>
                @foreach ($crYears as $year)
                    @php
                        $cr = $row->crGrades->firstWhere('year', $year);
                        $crFit = ($cr !== null && $cr->put_up !== false && $cr->has_grading !== false) ? $cr->integrityFit() : null;
                    @endphp
                    <td style="text-align: center; {{ $crFit === null ? '' : ($crFit ? 'background-color: #C6EFCE;' : 'background-color: #FFC7CE;') }}">
                        @if ($cr === null)
                            —
                        @elseif ($cr->put_up === false)
                            {{ __('dpc.tabulation.missing') }}
                        @elseif ($cr->has_grading === false)
                            {{ __('dpc.tabulation.no_grading') }}
                        @else
                            {{ $cr->grade ?? '—' }}@if ($cr->tribal_area === true) ({{ __('dpc.tabulation.tribal_short') }})@endif
                        @endif
                    </td>
                @endforeach
                @foreach ($propertyYears as $year)
                    @php
                        $property = $row->propertyFlags->firstWhere('year', $year);
                    @endphp
                    <td style="text-align: center;">
                        @if ($property === null)
                            —
                        @elseif ($property->submitted_on)
                            {{ $property->submitted_on->format('d-m-Y') }}@if ($property->isLate()) ({{ __('dpc.tabulation.late') }})@endif
                        @else
                            {{ __('dpc.tabulation.missing') }}
                        @endif
                    </td>
                @endforeach
                <td>{{ __('dpc.tabulation.birth') }}: {{ $row->dob_order_number ?? '—' }}; {{ __('dpc.tabulation.joining_letter') }}: {{ $row->joining_order_number ?? '—' }}</td>
                <td>{{ $row->is_ph === null ? '—' : ($row->is_ph ? __('ui.yes') : __('ui.no')) }}</td>
                <td>{{ __('dpc.tabulation.case') }}: {{ $row->enquiry_ongoing === null ? '—' : ($row->enquiry_ongoing ? __('ui.yes') : __('ui.no')) }}; {{ __('dpc.tabulation.punishment') }}: {{ $row->punishment_effective === null ? '—' : ($row->punishment_effective ? __('ui.yes') : __('ui.no')) }}</td>
                <td>{{ implode(' | ', $row->unfitReasons()) ?: '—' }}</td>
                <td>{{ $row->currentDocuments->count() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<table>
    <tr><td>{{ __('dpc.tabulation.legend_tribal') }}</td></tr>
</table>
