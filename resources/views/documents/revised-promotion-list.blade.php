{{-- S48's second table: the DPC's approved result with the released officers'
     `CIR` annotation cleared. M9 Phase 3.

     This is the SAME stored result the original DPC produced — read from the
     run the proceeding pinned at approval. The only difference from the
     original list is the CIR annotation, which is derived from hold state and
     never from a second calculation (AD-23). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Revised Promotion List — {{ $circulation->origin_dpc_code ?? '' }}</title>
    @include('documents._circulation-styles')
</head>
<body>
@include('documents._circulation-header', ['title' => 'REVISED PROMOTION LIST · संशोधित पदोन्नति सूचि'])

<div class="note">
    This list is the result approved at the original DPC, with the reservation cleared for the officer(s)
    the Commission has consented to. <strong>Marks, ranks and list positions are exactly as approved at that
    sitting</strong> — nothing has been recalculated.
    @if ($run)
        <br>Approved calculation run: <code>{{ $run->uuid }}</code>
    @endif
</div>

@if ($rows->isEmpty())
    <p><em>The original DPC's approved result could not be located.</em></p>
@else
    <h2>Result</h2>
    <table>
        <thead>
            <tr>
                <th>S.N.</th>
                <th>Officer</th>
                <th>Category</th>
                <th>List</th>
                <th>Position</th>
                <th>Remark</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['sn'] }}</td>
                <td>{{ $row['officer_name'] }}</td>
                <td>{{ $row['category'] }}</td>
                <td>{{ $row['list_type']?->value ?? '—' }}</td>
                <td>{{ $row['list_position'] ?? '—' }}</td>
                <td>
                    {{ $row['remark'] }}
                    {{-- After the circulation: CIR remains only where the
                         promotion is still reserved. --}}
                    @if ($row['held_after'])<span class="held">CIR</span>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@include('documents._circulation-effective-date')
@include('documents._circulation-footer')
</body>
</html>
