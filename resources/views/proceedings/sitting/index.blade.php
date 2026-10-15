@extends('layouts.app')

@section('title', __('proceeding.sitting.title'))

@section('content')
    {{-- The DPCs this person sits on (business instruction, 2026-09-07).

         Reached by a committee member, who holds no role — see the route group in
         routes/web.php for why this lives outside /cgpsc. --}}
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('proceeding.sitting.title') }}</h1>
            <p class="text-muted small mb-3">{{ __('proceeding.sitting.lead') }}</p>

            @forelse ($proposals as $proposal)
                @php($schedule = $sittings->schedule($proposal))
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <div class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</div>
                            <strong>{{ $proposal->promotionFromPost->name }} → {{ $proposal->promotionToPost->name }}</strong>
                            <div class="small text-body-secondary">{{ $proposal->department->name }}</div>
                            <div class="small mt-1">
                                @if ($schedule)
                                    <span class="badge text-bg-success">{{ __('proceeding.sitting.scheduled_for', ['when' => $schedule->scheduledFor()->format('d-m-Y H:i')]) }}</span>
                                    @if ($sittings->callWindowIsOpen($proposal))
                                        <span class="badge text-bg-primary">{{ __('proceeding.sitting.in_session') }}</span>
                                    @endif
                                @else
                                    <span class="badge text-bg-secondary">{{ __('proceeding.sitting.not_scheduled') }}</span>
                                @endif
                            </div>
                        </div>
                        <a href="{{ route('sitting.show', $proposal) }}" class="btn btn-primary btn-sm">
                            {{ __('proceeding.sitting.open_candidates') }}
                        </a>
                    </div>
                </div>
            @empty
                <div class="alert alert-secondary">{{ __('proceeding.sitting.none') }}</div>
            @endforelse
        </div>
    </div>
@endsection
