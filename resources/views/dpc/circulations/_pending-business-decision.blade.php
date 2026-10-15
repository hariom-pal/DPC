{{--
    The structured "Pending Business Decision" panel — M9 Phase 1.

    Deliberately **not** styled as an error. The department has done nothing
    wrong: the request was well-formed, the system recognised it, and the
    answer is genuinely unruled. Presenting it in red beside validation
    failures would teach users that a case waiting on CGPSC is a mistake of
    theirs to fix.

    It states what was *not* done as prominently as what was, because that is
    the part a reader cannot otherwise verify.
--}}

<div class="alert alert-warning" role="status">
    <h2 class="h6 mb-1">
{{ __('dpc.circulations.pending.heading', ['code' => $pending['code']]) }}
    </h2>
    <p class="mb-2"><strong>{{ $pending['title'] }}</strong></p>
    <p class="mb-2">{{ $pending['question'] }}</p>

    @if (! empty($pending['affected']))
        <p class="mb-1 small text-uppercase fw-semibold">{{ __('dpc.circulations.pending_business_decision.officers_affected') }}</p>
        <ul class="mb-2">
            @foreach ($pending['affected'] as $officer)
                {{-- The panel is shared across business decisions, and their
                     payloads differ: BD-15 names a hold ground, BD-14 names a
                     release date. Neither is assumed. --}}
                <li>
{{ __('dpc.circulations.pending.officer', ['sn' => $officer['sn'], 'name' => $officer['officer_name']]) }}
                    @if (! empty($officer['reason']))
                        <span class="text-body-secondary">({{ $officer['reason'] }})</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (! empty($pending['not_done']))
        <p class="mb-1 small text-uppercase fw-semibold">{{ __('dpc.circulations.pending_business_decision.what_the_system_did_not_do') }}</p>
        <ul class="mb-2">
            @foreach ($pending['not_done'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    <p class="mb-0 small text-body-secondary">
{{ __('dpc.circulations.pending.recorded_on_timeline') }}
        @if (! empty($pending['route']))
{!! __('dpc.circulations.pending.tracked_in', ['route' => e($pending['route'])]) !!}
        @endif
    </p>
</div>
