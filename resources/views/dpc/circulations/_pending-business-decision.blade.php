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
        Pending Business Decision — {{ $pending['code'] }}
    </h2>
    <p class="mb-2"><strong>{{ $pending['title'] }}</strong></p>
    <p class="mb-2">{{ $pending['question'] }}</p>

    @if (! empty($pending['affected']))
        <p class="mb-1 small text-uppercase fw-semibold">Officers affected</p>
        <ul class="mb-2">
            @foreach ($pending['affected'] as $officer)
                <li>
                    SN {{ $officer['sn'] }} — {{ $officer['officer_name'] }}
                    <span class="text-body-secondary">({{ $officer['reason'] }})</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if (! empty($pending['not_done']))
        <p class="mb-1 small text-uppercase fw-semibold">What the system did not do</p>
        <ul class="mb-2">
            @foreach ($pending['not_done'] as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    <p class="mb-0 small text-body-secondary">
        Recorded on this DPC's activity timeline.
        @if (! empty($pending['route']))
            Tracked in <code>{{ $pending['route'] }}</code>.
        @endif
    </p>
</div>
