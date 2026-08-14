{{--
    One chart cell. A corrected value is shown with the department's original
    struck through beside it — the same old-beside-new convention M6 uses for
    superseded allottees and schedule slots, so the Commission reads a
    correction the way it already reads a re-allotment.
--}}
@if ($cell === null)
    <span class="text-muted">—</span>
@elseif ($cell['corrected'])
    <del class="text-muted">{{ $cell['submitted'] ?? '(blank)' }}</del>
    <strong class="text-primary">{{ $cell['effective'] ?? '(blank)' }}</strong>
@else
    {{ $cell['effective'] }}
@endif
