{{-- PPT S34 — the Main List and Waiting List the Commission approved. --}}
@include('documents._styles')
@include('documents._header', ['title' => 'Promotion List — Main and Supplementary (मुख्य सूचि तथा अनुपूरक सूचि)'])

@if ($run === null)
    <p>No approved calculation is attached to this proceeding.</p>
@else
    @if ($run->has_pending_decisions)
        <div class="provisional">
            <strong>PROVISIONAL.</strong> A business rule affecting this result has not been settled by the
            Commission. The printed rule has been applied and the affected cases flagged rather than guessed.
            This list must not be treated as final until that rule is confirmed.
            <ul>
                @foreach ((array) $run->pending_decisions as $pending)
                    <li>{{ $pending }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <h3>
        PROMOTION AS ON {{ $proceeding->promotion_as_on_year ?? '—' }}
        ON {{ $proposal->postToCadre?->name }} FROM {{ $proposal->postFromCadre?->name }} — MAIN LIST
    </h3>
    <table>
        <thead>
            <tr><th>SN</th><th>Name</th><th>Category</th><th>Mark</th><th>Remark</th></tr>
        </thead>
        <tbody>
        @forelse ($lists['main'] as $index => $result)
            @php $held = $holds->get($result->sn); @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $result->officer_name }}</td>
                <td>{{ $result->reservation_category_code }}</td>
                <td>{{ $result->score_breakdown['total'] ?? '—' }}</td>
                <td>
                    {{ $result->remark }}@if ($result->is_super_seat) SS @endif
                    @if ($held)<span class="held">CIR</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5">No officers on the main list.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h3>WAITING LIST (अनुपूरक सूचि)</h3>
    <table>
        <thead>
            <tr><th>SN</th><th>Name</th><th>Category</th><th>Mark</th><th>Remark</th></tr>
        </thead>
        <tbody>
        @forelse ($lists['waiting'] as $index => $result)
            @php $held = $holds->get($result->sn); @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $result->officer_name }}</td>
                <td>{{ $result->reservation_category_code }}</td>
                <td>{{ $result->score_breakdown['total'] ?? '—' }}</td>
                <td>
                    {{ $result->remark }}@if ($result->is_super_seat) SS @endif
                    @if ($held)<span class="held">CIR</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5">No officers on the waiting list.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($holds->isNotEmpty())
        <h3>Promotions Held (परिभ्रमण / CIR)</h3>
        <p>
            The officers below took part in this DPC, but the promotion action in respect of each is
            <strong>reserved</strong> until the matter noted against them is resolved. A hold is not a
            finding on merit.
        </p>
        <table>
            <thead><tr><th>SN</th><th>Officer</th><th>Reason</th><th>Detail</th></tr></thead>
            <tbody>
            @foreach ($holds as $sn => $officerHolds)
                @foreach ($officerHolds as $hold)
                    <tr>
                        <td>{{ $hold->sn }}</td>
                        <td>{{ $hold->officer_name }}</td>
                        <td>{{ $hold->reason_code->label() }}</td>
                        <td>{{ $hold->detail }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    @endif
@endif

@include('documents._footer')
