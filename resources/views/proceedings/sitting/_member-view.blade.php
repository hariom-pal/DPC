{{-- The committee members' view of the DPC (2026-11-24): shown to its DPC Chairman. --}}
@php
    $memberView = app(\App\Domain\Proceeding\Services\MemberViewService::class);
    $memberViewAllotment = $memberView->allotment($proposal);
@endphp
@if ($memberViewAllotment !== null && (int) $memberViewAllotment->allotted_to === (int) auth()->id())
    <div class="card border-0 shadow-sm mb-3" id="member-view">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small">
                <div class="fw-semibold">{{ __('proceeding.member_view.heading') }}</div>
                @if ($memberView->isActive($proposal))
                    @php($memberViewSince = $proposal->member_view_enabled_at ?? $memberView->deadline($proposal))
                    <span class="text-success" data-member-view="open">{{ __($memberView->activatedManually($proposal) ? 'proceeding.member_view.open' : 'proceeding.member_view.open_auto', ['date' => $memberViewSince?->format('d-m-Y H:i')]) }}</span>
                @else
                    <span class="text-warning-emphasis" data-member-view="closed">{{ __('proceeding.member_view.closed', ['date' => $memberView->deadline($proposal)?->format('d-m-Y H:i')]) }}</span>
                @endif
            </div>
            @if ($memberView->mayActivate($proposal, auth()->user()))
                <form method="POST" action="{{ route('sitting.member-view.activate', $proposal) }}" class="m-0">
                    @csrf
                    <button class="btn btn-primary btn-sm" id="activate-member-view">{{ __('proceeding.member_view.activate') }}</button>
                </form>
            @endif
        </div>
    </div>
@endif
