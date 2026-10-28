{{-- The open eSign issue of a Pre-DPC or DPC (2026-10-28): the latest attempt at
     the step it waits on failed. Expects $proposal. --}}
@php($esignIssue = app(\App\Domain\Signature\Services\SignatureAttemptLog::class)->openIssue($proposal))
@if ($esignIssue)
    <div class="alert alert-danger small" role="alert" id="esign-issue">
        <strong>{{ __('esign.issue.title') }}</strong>
        <div>
            @if ($esignIssue->outcome === 'name_mismatch')
                {{ __('esign.issue.name_mismatch', ['officer' => $esignIssue->user?->name, 'asserted' => $esignIssue->asserted_name ?? '—', 'portal' => $esignIssue->portal_name ?? '—', 'date' => $esignIssue->created_at->format('d-m-Y H:i')]) }}
            @else
                {{ __('esign.issue.other', ['officer' => $esignIssue->user?->name, 'reason' => __('esign.errors.'.$esignIssue->outcome), 'date' => $esignIssue->created_at->format('d-m-Y H:i')]) }}
            @endif
        </div>
        <div class="mt-1">{{ __('esign.issue.next') }}</div>
    </div>
@endif
