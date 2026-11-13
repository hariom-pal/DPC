{{-- Where a DPC stands, in the MoM's colours (12 November 2026, point 2): Closed red,
     pending at the AO/AH green, with CGPSC (the AO/AH part done) blue. --}}
@props(['proposal'])
@php
    $dpcPhase = app(\App\Domain\Dpc\Services\DpcCompletionService::class)->phase($proposal);
@endphp
<span {{ $attributes->merge(['class' => 'badge '.\App\Domain\Dpc\Services\DpcCompletionService::badgeClass($dpcPhase)]) }} data-dpc-phase="{{ $dpcPhase }}">{{ __('dpc.phase.'.$dpcPhase) }}</span>
