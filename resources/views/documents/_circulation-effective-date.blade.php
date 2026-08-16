{{-- §23.1 — no effective date is invented.

     Two facts are stated and NEITHER is asserted as governing: the origin
     DPC's promotion-as-on year (captured at approval, never derived — AQ-7)
     and the date the reservation was released. Which of them governs
     seniority and pay is BD-14 — a service-law consequence the Commission
     states, not a field this document computes. --}}
<h2>Basis of the promotion</h2>
<div class="note">
    <p>
        <strong>Promotion as on (from the original DPC):</strong>
        {{ $promotionAsOnYear ?? 'not recorded' }}
    </p>
    <p>
        <strong>Reservation released on:</strong>
        @php $released = $items->firstWhere('hold_released_at', '!=', null); @endphp
        {{ $released?->hold_released_at?->format('d-m-Y') ?? '—' }}
    </p>
    @if (! $effectiveDateBasis)
        <p class="pending">
            <strong>Pending Business Decision (BD-14)</strong> — from what date a circulated promotion
            takes effect has not been settled by the Commission. <strong>This document therefore states
            both dates above and asserts neither as the effective date.</strong> It is not published to
            the department until that decision is taken.
        </p>
    @else
        <p>
            <strong>Effective date basis:</strong>
            {{ $effectiveDateBasis === 'origin_dpc_year'
                ? 'Retrospective to the original DPC’s promotion-as-on year'
                : 'From the date of the Commission’s consent' }}
        </p>
    @endif
</div>
