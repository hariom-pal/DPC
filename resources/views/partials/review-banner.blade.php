{{--
    Shown on every screen of a Review DPC (architect Directive 3, M8).

    A Review looks exactly like a Fresh DPC — same screens, same fields, same
    workflow — which is precisely why it needs saying. Someone opening this
    proposal must not have to notice a type field to realise they are correcting
    a promotion that has already been decided and published.
--}}
<div class="alert alert-warning border-warning d-flex align-items-center gap-3 py-2 mb-3">
    <strong class="text-uppercase">{{ __('ui.partials.review_banner.this_is_a_review_dpc') }}</strong>
    <span class="small flex-grow-1">
        @if (! empty($reviewChainContext['original_dpc_code']))
{!! __('ui.partials.review_banner.correcting', [
                'code' => e($reviewChainContext['original_dpc_code']),
                'date' => e($reviewChainContext['original_proposal_date']),
            ]) !!}
        @endif
        @if (! empty($reviewChainContext['review_number']))
{{ __('ui.partials.review_banner.review_number', ['number' => $reviewChainContext['review_number']]) }}
        @endif
        @if (! empty($reviewChainContext['reasons']))
{{ __('ui.partials.review_banner.reasons', ['reasons' => implode(', ', $reviewChainContext['reasons'])]) }}
        @endif
    </span>
    {{-- Only for a viewer who can actually open it. The banner reaches the
         Commission's own screens too — see ReviewDpcComposer::canViewChain() —
         and this button used to take a Chairman to an access-denied page. --}}
    @if (! empty($reviewChainContext['proposal']) && ($reviewChainContext['can_view_chain'] ?? false))
        <a class="btn btn-sm btn-outline-dark"
           href="{{ route('dpc-proposals.reviews.chain', $reviewChainContext['proposal']) }}">
            {{ __('ui.review_chain') }}
        </a>
    @endif
</div>
