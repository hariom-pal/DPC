<div class="meta">
    Generated {{ $renderedAt->format('d-m-Y H:i') }} ·
    Generator {{ $generatorVersion }} (build {{ $generatorCommit }}) ·
    Circulation UUID {{ $circulation->uuid }}
    @if ($circulation->consented_by_name)
        <br>Consented by {{ $circulation->consented_by_name }}
        @if ($circulation->consented_at) on {{ $circulation->consented_at->format('d-m-Y H:i') }} @endif
    @endif
    <br>This document is issued electronically and its integrity is verifiable by its SHA-256 content hash.
</div>
