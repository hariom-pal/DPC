{{-- Every circulation document states what it is and what it belongs to,
     before anything else — the reader most likely to be misled is the one
     reading it long afterwards with nobody left to ask (AQ-13's reasoning,
     applied here). --}}
<h1>छत्तीसगढ़ लोक सेवा आयोग · Chhattisgarh Public Service Commission</h1>
<p class="sub">
    {{ $title }}<br>
    <strong>Circulation:</strong> {{ $circulation->circulation_code ?? '—' }} ·
    <strong>Origin DPC:</strong> {{ $circulation->origin_dpc_code ?? '—' }}
    @if ($circulation->origin_dpc_date)
        dated {{ $circulation->origin_dpc_date->format('d-m-Y') }}
    @endif
    <br>
    <strong>Department:</strong> {{ $circulation->department?->name ?? '—' }} ·
    <strong>Circulation proposal date:</strong> {{ $circulation->proposal_date->format('d-m-Y') }}
</p>
