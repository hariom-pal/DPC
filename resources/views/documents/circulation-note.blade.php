{{-- The परिभ्रमण टीप — the prescribed form S45 calls "निर्धारित प्रारुप",
     sent to the Commission for signature (C3/C7). M9 Phase 3.

     Rendered entirely from stored data. Nothing is recalculated (AD-23). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Circulation Note — {{ $circulation->circulation_code ?? 'draft' }}</title>
    @include('documents._circulation-styles')
</head>
<body>
@include('documents._circulation-header', ['title' => 'CIRCULATION NOTE · परिभ्रमण टीप'])

<h2>Purpose</h2>
<p>
    During the departmental promotion committee held for the post below, the promotion action in respect of
    the officer(s) named was <strong>reserved</strong> because the record shown against each was incomplete.
    The department has since supplied the missing record. The Commission's consent is sought, in the
    prescribed form, for the promotion action to proceed.
</p>

<h2>Officers</h2>
<table>
    <thead>
        <tr>
            <th>S.N.</th>
            <th>Officer</th>
            <th>Ground on which the promotion was reserved</th>
            <th>Resolution received</th>
            <th>What was received</th>
            <th>Reference</th>
            <th>Decision of the Commission</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($items as $item)
        <tr>
            <td>{{ $item->sn }}</td>
            <td>{{ $item->officer_name }}</td>
            <td>{{ $item->reason_code->label() }}</td>
            <td>{{ $item->resolution_received_on->format('d-m-Y') }}</td>
            <td>{{ $item->resolution_detail }}</td>
            <td>{{ $item->resolution_reference ?? '—' }}</td>
            <td>
                {{ $item->decision?->label() ?? 'not decided' }}
                @if ($item->hold_released_at)
                    <br><em>released {{ $item->hold_released_at->format('d-m-Y') }}</em>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

@include('documents._circulation-effective-date')

<h2>What this note does not do</h2>
<div class="note">
    This note resolves a promotion action that was reserved at the original sitting. <strong>It does not
    re-run the DPC.</strong> No officer's marks have been recalculated, no eligibility or zone has been
    re-determined, and no other officer's position on the promotion list has changed.
</div>

@include('documents._circulation-footer')
</body>
</html>
