{{-- The message for one field, shown directly under it. $bag defaults to the page's own. --}}
@if ($errors->getBag($bag ?? 'default')->has($field))
    <div class="invalid-feedback d-block">{{ $errors->getBag($bag ?? 'default')->first($field) }}</div>
@endif
