{{--
    Shared header for both documents. Every generated document states what it
    is, which sitting produced it, and which calculation it rests on — the
    three permanent references (architect Directive 2), plus the versions that
    let it be reproduced years later.
--}}
<div class="doc-header">
    <h1>छत्तीसगढ़ लोक सेवा आयोग · Chhattisgarh Public Service Commission</h1>
    <h2>{{ $title }}</h2>
    <table class="meta">
        <tr>
            <th>Department</th><td>{{ $proposal->department?->name }}</td>
            <th>DPC ID</th><td>{{ $proposal->dpc_code ?? '—' }}</td>
        </tr>
        <tr>
            <th>Promotion From</th><td>{{ $proposal->postFromCadre?->name }}</td>
            <th>Promotion To</th><td>{{ $proposal->postToCadre?->name }}</td>
        </tr>
        <tr>
            <th>Proceeding</th><td class="ref">{{ $proceeding->uuid }}</td>
            <th>Proposal</th><td class="ref">{{ $proposal->uuid }}</td>
        </tr>
        <tr>
            <th>Calculation</th><td class="ref">{{ $run?->uuid ?? '—' }}</td>
            <th>Rule / Engine</th><td>{{ $run?->rule_version ?? '—' }} / {{ $engineVersion ?? '—' }}</td>
        </tr>
        <tr>
            <th>Conducted By</th>
            <td>{{ $proceeding->conducted_by_name }}, {{ $proceeding->opened_at->format('d/m/Y H:i') }}</td>
            <th>Concluded By</th>
            <td>
                {{ $proceeding->closed_by_name ?? '—' }}
                @if ($proceeding->closed_at), {{ $proceeding->closed_at->format('d/m/Y H:i') }}@endif
            </td>
        </tr>
    </table>
</div>
