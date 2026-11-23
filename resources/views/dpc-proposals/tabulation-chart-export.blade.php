{{-- The Tabulation Chart workbook (2026-11-18). Fit and Unfit are the cell colour. --}}
<table>
    <thead>
        <tr>
            <th colspan="{{ 8 + $crColumns + count($propertyYears) + 5 }}" style="font-weight: bold; font-size: 13px;">{{ __('dpc.tabulation.title') }} — {{ $proposal->citation() }}</th>
        </tr>
        <tr>
            <th colspan="8" style="font-weight: bold; background-color: #E9ECEF; text-align: center;">{{ __('dpc.tabulation.group_csv') }}</th>
            <th colspan="{{ $crColumns + count($propertyYears) + 5 }}" style="font-weight: bold; background-color: #CFF4FC; text-align: center;">{{ __('dpc.tabulation.group_portal') }}</th>
        </tr>
        <tr>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.employee_details.seniority_no') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.employee_details.employee_id') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('ui.name') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.tabulation.dob') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('ui.category') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.employee_details.current_post') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.tabulation.qualification') }}</th>
            <th rowspan="2" style="font-weight: bold; background-color: #E9ECEF;">{{ __('dpc.tabulation.joining') }}</th>
            @for ($n = 1; $n <= $crColumns; $n++)
                <th rowspan="2" style="font-weight: bold; background-color: #CFF4FC; text-align: center;">{{ __('dpc.tabulation.cr_n', ['n' => $n]) }}</th>
            @endfor
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
                <td>{{ $row->reservationCategory?->code ?? '—' }}</td>
                <td>{{ $row->promotion_from_post ?? '—' }}</td>
                <td>{{ $row->qualification ?? '—' }}</td>
                <td>{{ $row->date_of_joining_service?->format('d-m-Y') ?? '—' }}</td>
                {{-- Each cell its own year and grade, in the CR window's order (2026-11-19). --}}
                {{-- Only the years counted, each with its grade (2026-11-22); a missing year is not shown. --}}
                @for ($n = 0; $n < $crColumns; $n++)
                    @php
                        $cell = $crCells[$row->id][$n] ?? null;
                        $cr = $cell === null ? null : $row->crGrades->firstWhere('year', $cell['year']);
                        $crFit = ($cr !== null && $cr->has_grading !== false) ? $cr->integrityFit() : null;
                        $substituted = $cell !== null && $cell['state'] !== 'counted';
                        $fill = $substituted ? 'background-color: #FFEB9C;' : ($crFit === null ? '' : ($crFit ? 'background-color: #C6EFCE;' : 'background-color: #FFC7CE;'));
                    @endphp
                    <td style="text-align: center; {{ $fill }}">
                        @if ($cell !== null)
                            {{ $cell['year'] }}:
                            @if ($cr->has_grading === false)
                                ({{ __('dpc.tabulation.ng_short') }})
                            @else
                                {{ $cr->grade ?? '—' }}@if ($cr->tribal_area === true) ({{ __('dpc.tabulation.tribal_short') }})@endif @if ($substituted && $cr->integrityOption) [{{ $cr->integrityOption->label() }}]@endif
                            @endif
                        @endif
                    </td>
                @endfor
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
    <tr><td>{{ __('dpc.tabulation.missing_cr_note') }}</td></tr>
    <tr><td>{{ __('dpc.tabulation.legend_tribal') }}</td></tr>
    <tr><td style="background-color: #FFEB9C;">{{ __('dpc.tabulation.legend_substituted') }}</td></tr>
</table>
