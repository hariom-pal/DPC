{{--
    PPT S31–S33 — what the Commission did at the sitting, as distinct from what
    it decided. A tribunal examining how a promotion was reached needs this;
    a department contesting one needs the promotion list.
--}}
@include('documents._styles')
@include('documents._header', ['title' => 'Proceedings of the Departmental Promotion Committee'])

<h3>Conduct of the Proceeding</h3>
<table>
    <tr><th>Scheduled for</th>
        <td>
            @if ($proceeding->schedule)
                {{ $proceeding->schedule->dpc_date->format('d/m/Y') }}
                {{ \Illuminate\Support\Carbon::parse($proceeding->schedule->dpc_time)->format('H:i') }}
            @else — @endif
        </td>
    </tr>
    <tr><th>Opened</th><td>{{ $proceeding->opened_at->format('d/m/Y H:i') }} by {{ $proceeding->conducted_by_name }}</td></tr>
    <tr><th>Concluded</th>
        <td>
            @if ($proceeding->closed_at)
                {{ $proceeding->closed_at->format('d/m/Y H:i') }} by {{ $proceeding->closed_by_name }}
            @else — @endif
        </td>
    </tr>
    <tr><th>Outcome</th><td>{{ $proceeding->status->label() }}</td></tr>
    @if ($proceeding->cancellation_reason)
        <tr><th>Reason for cancellation</th><td>{{ $proceeding->cancellation_reason }}</td></tr>
    @endif
    <tr>
        <th>Department submission</th>
        <td class="ref">{{ $proceeding->proposal_version }}</td>
    </tr>
</table>

<p>
    The fingerprint above identifies the department's submission exactly as it stood when this sitting
    opened. Entries made by the department after the detailed proposal was forwarded are not part of it.
</p>

<h3>Corrections Recorded by the Commission</h3>
@if ($corrections->isEmpty())
    <p>No correction was recorded during this sitting. The chart was considered as the department submitted it.</p>
@else
    <p>
        Each correction below was recorded <strong>beside</strong> the department's entry, never over it. The
        department's own entry is shown struck through.
    </p>
    <table>
        <thead>
            <tr><th>#</th><th>SN</th><th>Officer</th><th>Field</th><th>Year</th>
                <th>Department entry</th><th>Corrected to</th><th>Reason</th><th>By</th></tr>
        </thead>
        <tbody>
        @foreach ($corrections as $correction)
            <tr>
                <td>{{ $correction->sequence }}</td>
                <td>{{ $correction->sn }}</td>
                <td>{{ $correction->officer_name }}</td>
                <td>{{ \App\Domain\Proceeding\Enums\CorrectableField::tryFrom($correction->field)?->label() ?? $correction->field }}</td>
                <td>{{ $correction->year ?? '—' }}</td>
                <td><del>{{ $correction->submitted_value ?? '(blank)' }}</del></td>
                <td>{{ $correction->new_value ?? '(blank)' }}</td>
                <td>{{ $correction->reason }}</td>
                <td>{{ $correction->applied_by_name }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h3>Promotions Held (परिभ्रमण / CIR)</h3>
@if ($holds->isEmpty())
    <p>No promotion was held in this proceeding.</p>
@else
    <table>
        <thead><tr><th>SN</th><th>Officer</th><th>Reason</th><th>Detail</th><th>Recorded by</th></tr></thead>
        <tbody>
        @foreach ($holds as $officerHolds)
            @foreach ($officerHolds as $hold)
                <tr>
                    <td>{{ $hold->sn }}</td>
                    <td>{{ $hold->officer_name }}</td>
                    <td>{{ $hold->reason_code->label() }}</td>
                    <td>{{ $hold->detail }}</td>
                    <td>{{ $hold->recorded_by_name }}{{ $hold->is_automatic ? ' (from the chart)' : '' }}</td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
@endif

<h3>Result</h3>
@if ($run === null)
    <p>No calculation is attached to this proceeding.</p>
@else
    <table>
        <tr><th>Officers considered</th><td>{{ $run->officers_considered }}</td>
            <th>Found eligible</th><td>{{ $run->eligible_count }}</td></tr>
        <tr><th>Main list</th><td>{{ $run->main_list_count }}</td>
            <th>Waiting list</th><td>{{ $run->waiting_list_count }}</td></tr>
    </table>
    <p>The Main and Waiting lists themselves are issued as a separate Promotion List document.</p>
@endif

@include('documents._footer')
