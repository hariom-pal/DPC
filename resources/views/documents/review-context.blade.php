{{--
    Prepended to every document a Review DPC produces (architect ruling AQ-13).

    A promotion list dated today that silently corrects one from years ago is
    misleading on its face, and the reader most likely to be misled is the one
    reading it long afterwards with nobody left to ask. All five facts the
    ruling names appear here, before anything else on the page.
--}}
<div style="border:2px solid #333; padding:10px; margin:0 0 14px; font-family:'DejaVu Sans',Arial,sans-serif; font-size:10pt;">
    <div style="font-weight:bold; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px;">
        Review DPC — Review {{ $link->review_number }} of this promotion
    </div>
    <table style="border-collapse:collapse; width:100%; font-size:9.5pt;">
        <tr>
            <th style="border:1px solid #999; background:#f2f2f2; text-align:left; padding:3px 6px; width:22%;">Original DPC</th>
            <td style="border:1px solid #999; padding:3px 6px;">{{ $original?->dpc_code ?? '—' }}</td>
            <th style="border:1px solid #999; background:#f2f2f2; text-align:left; padding:3px 6px; width:22%;">Original DPC date</th>
            <td style="border:1px solid #999; padding:3px 6px;">{{ $original?->proposal_date?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <th style="border:1px solid #999; background:#f2f2f2; text-align:left; padding:3px 6px;">Review DPC</th>
            <td style="border:1px solid #999; padding:3px 6px;">{{ $review->dpc_code ?? '—' }}</td>
            <th style="border:1px solid #999; background:#f2f2f2; text-align:left; padding:3px 6px;">Review DPC date</th>
            <td style="border:1px solid #999; padding:3px 6px;">{{ $review->proposal_date->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <th style="border:1px solid #999; background:#f2f2f2; text-align:left; padding:3px 6px;">Reason for review</th>
            <td colspan="3" style="border:1px solid #999; padding:3px 6px;">
                @forelse ($reasons as $reason)
                    {{ $reason->reason?->name_en ?: $reason->reason?->code }}@if ($reason->detail) — {{ $reason->detail }}@endif
                    @if (! $loop->last)<br>@endif
                @empty
                    —
                @endforelse
            </td>
        </tr>
    </table>
    <div style="margin-top:6px; font-size:8.5pt; color:#444;">
        This DPC corrects the one named above. Where the two differ, this one governs.
    </div>

    {{-- Directive 3: the four permanent references, on the document itself. --}}
    <table style="border-collapse:collapse; width:100%; margin-top:8px; font-size:8.5pt;">
        <tr>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px; width:20%;">Original DPC</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $original?->uuid ?? '—' }}</td>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px; width:20%;">Review DPC</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $review->uuid }}</td>
        </tr>
        <tr>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px;">Chain</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $link->chain_uuid }}</td>
            <th style="border:1px solid #999; background:#f7f7f7; text-align:left; padding:3px 6px;">This document</th>
            <td style="border:1px solid #999; padding:3px 6px; font-family:monospace;">{{ $documentUuid ?? '—' }}</td>
        </tr>
    </table>
</div>
