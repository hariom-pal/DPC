{{--
    Prepended when a superseded DPC's document is served (architect Directive 5,
    M8 Phase 1; refined by Directives 1–3 after Phase 3).

    Applied on delivery, never baked into the stored file: the artefact was
    issued as it stands and was *later* superseded. Rewriting it would both
    assert otherwise and break the hash that proves a department's copy is the
    copy the Commission gave it.
--}}
<div style="border:3px solid #b00; padding:12px; margin:0 0 14px; font-family:'DejaVu Sans',Arial,sans-serif;">
    <div style="color:#b00; font-size:20pt; font-weight:bold; letter-spacing:4px; text-align:center;">
        SUPERSEDED
    </div>

    {{--
        Directive 1. Read from the **superseding link**, not from the document's
        own reference row: a Review that is itself later reviewed has its own
        DPC code on that row, and printing it here would have the document
        declare itself superseded by itself.
    --}}
    <div style="text-align:center; font-size:11pt; margin-top:8px; font-weight:bold;">
        Superseded By: {{ $review?->dpc_code ?? $reference?->review_dpc_code ?? 'Review '.$link->review_number }}
    </div>

    <div style="text-align:center; font-size:10pt; margin-top:6px;">
        This document was issued as it stands and remains a valid record of what was decided at the time.
        It has since been superseded by the Review DPC named above
        @php $effective = $reference?->supersession_effective_at ?? $link->superseded_at; @endphp
        @if ($effective)
            with effect from {{ $effective->format('d/m/Y H:i') }}
        @endif
        (review {{ $link->review_number }}). Where the two differ, the later DPC governs.
    </div>

    {{-- Directive 3: the four permanent references, on the document itself. --}}
    <table style="border-collapse:collapse; width:100%; margin-top:10px; font-size:8.5pt;">
        <tr>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px; width:20%;">Original DPC</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $reference?->original_dpc_uuid ?? '—' }}</td>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px; width:20%;">Review DPC</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $reference?->review_dpc_uuid ?? '—' }}</td>
        </tr>
        <tr>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px;">Chain</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $reference?->chain_uuid ?? $link->chain_uuid }}</td>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px;">This document</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $reference?->document_uuid ?? $document?->uuid ?? '—' }}</td>
        </tr>
        <tr>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px;">Superseded by</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $review?->uuid ?? '—' }}</td>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px;">Effective</th>
            <td style="border:1px solid #999; padding:3px 6px;">{{ $effective?->toIso8601String() ?? '—' }}</td>
        </tr>
    </table>
</div>
