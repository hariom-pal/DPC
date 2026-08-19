{{-- The S51 data table, rendered once and included by both the screen and the
     printable copy — so the paper an officer takes to their department cannot
     disagree with what they were shown.

     Columns are S51's own, in S51's order: NAME OF US · DOB · QUAL · DOP · CAT ·
     LOYALTY (per year) · IMMOVABLE PROPERTY DETAILS (per year) · YEAR WISE CR
     (per year) · NOTE.

     A year with no value renders as an em dash, not as a blank and not as "N".
     A missing CR year or property return is exactly why a Circulation DPC exists,
     so it is data in its own right and must be visibly absent rather than
     silently filled.

     Expects: $row, $years, $cr, $loyalty, $property --}}

<div class="table-responsive">
    <table class="table table-bordered table-sm align-middle mb-0" style="font-size:.875rem">
        <thead class="table-light">
            <tr>
                <th rowspan="2" class="align-middle">Name</th>
                <th rowspan="2" class="align-middle">DOB</th>
                <th rowspan="2" class="align-middle">Qual.</th>
                <th rowspan="2" class="align-middle">DOP</th>
                <th rowspan="2" class="align-middle">Cat.</th>
                @if ($years !== [])
                    <th colspan="{{ count($years) }}" class="text-center">Loyalty</th>
                    <th colspan="{{ count($years) }}" class="text-center">Immovable Property</th>
                    <th colspan="{{ count($years) }}" class="text-center">Year-wise CR</th>
                @endif
                <th rowspan="2" class="align-middle">Note</th>
            </tr>
            <tr>
                @foreach (['loyalty', 'property', 'cr'] as $group)
                    @foreach ($years as $year)
                        <th class="text-center small text-body-secondary">{{ $year }}</th>
                    @endforeach
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="fw-semibold">{{ $row->name }}</td>
                <td>{{ $row->dob?->format('d-m-Y') ?? '—' }}</td>
                <td>{{ $row->qualification ?: '—' }}</td>
                <td>{{ $row->date_of_posting_on_current_post?->format('d-m-Y') ?? '—' }}</td>
                <td>{{ $row->reservationCategory?->code ?? '—' }}</td>

                @foreach ($years as $year)
                    @php($flag = $loyalty[$year] ?? null)
                    <td class="text-center">{{ $flag === null ? '—' : ($flag ? 'Y' : 'N') }}</td>
                @endforeach

                @foreach ($years as $year)
                    @php($flag = $property[$year] ?? null)
                    <td class="text-center">{{ $flag === null ? '—' : ($flag ? 'Y' : 'N') }}</td>
                @endforeach

                @foreach ($years as $year)
                    <td class="text-center">{{ $cr[$year] ?? '—' }}</td>
                @endforeach

                <td>{{ $row->note ?: '—' }}</td>
            </tr>
        </tbody>
    </table>
</div>

@if ($years === [])
    <p class="small text-body-secondary mt-2 mb-0">
        Your department has recorded no year-wise CR, loyalty or immovable-property entries against
        your name on this proposal. If that is not correct, raise it with them.
    </p>
@endif
