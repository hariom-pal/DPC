{{--
    Shown on every screen of a Review DPC (architect Directive 3, M8).

    A Review looks exactly like a Fresh DPC — same screens, same fields, same
    workflow — which is precisely why it needs saying. Someone opening this
    proposal must not have to notice a type field to realise they are correcting
    a promotion that has already been decided and published.
--}}
<div class="alert alert-warning border-warning d-flex align-items-center gap-3 py-2 mb-3">
    <strong class="text-uppercase">This is a Review DPC</strong>
    <span class="small flex-grow-1">
        @if (! empty($reviewChainContext['original_dpc_code']))
            Correcting <code>{{ $reviewChainContext['original_dpc_code'] }}</code>
            of {{ $reviewChainContext['original_proposal_date'] }}.
        @endif
        @if (! empty($reviewChainContext['review_number']))
            Review {{ $reviewChainContext['review_number'] }} in this chain.
        @endif
        @if (! empty($reviewChainContext['reasons']))
            Reasons: {{ implode(', ', $reviewChainContext['reasons']) }}.
        @endif
    </span>
    @if (! empty($reviewChainContext['proposal']))
        <a class="btn btn-sm btn-outline-dark"
           href="{{ route('dpc-proposals.reviews.chain', $reviewChainContext['proposal']) }}">
            Review Chain
        </a>
    @endif
</div>
