{{-- A consistent empty state.

     An empty table with no explanation reads as a fault. This says what is
     absent, why, and what to do about it.

     Usage:
       @include('partials.empty-state', [
           'title' => 'No circulation raised yet',
           'text'  => 'Raise one against a concluded DPC with a reserved promotion.',
           'icon'  => 'inbox',           // optional
           'action' => ['label' => 'Raise a circulation', 'href' => $url], // optional
       ])
--}}
<div class="empty-state">
    <div class="empty-icon">@include('partials.icon', ['name' => $icon ?? 'inbox', 'size' => 30])</div>
    <h3>{{ $title ?? 'Nothing here yet' }}</h3>
    @if (! empty($text))
        <p>{{ $text }}</p>
    @endif
    @if (! empty($action))
        <a href="{{ $action['href'] }}" class="btn btn-primary btn-sm mt-3">{{ $action['label'] }}</a>
    @endif
</div>
