{{-- Shared flash + validation display.

     Presentation only — the same session keys and the same $errors bag as
     before. Icons are inline SVG so an alert never renders as a broken glyph. --}}

{{-- Once per page: a screen that places the alerts itself is rendered before the
     layout, so its placement wins and the layout's copy is skipped. --}}
@once


@if (session('status'))
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
        @include('partials.icon', ['name' => 'flow-check', 'size' => 20])
        <div>{{ session('status') }}</div>
    </div>
@endif

@if (session('warning'))
    <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
        @include('partials.icon', ['name' => 'bell', 'size' => 20])
        <div>{{ session('warning') }}</div>
    </div>
@endif

@if (($inlineErrors ?? false) && collect($errors->getBags())->contains(fn ($bag) => $bag->any()))
    {{-- The screen shows each message beside its field; here only a pointer to them,
         and the messages that belong to no one field. --}}
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        @include('partials.icon', ['name' => 'shield', 'size' => 20])
        <div>
            <strong class="d-block">{{ __('vacancymaster.errors.fix_highlighted') }}</strong>
            @if ($errors->has('workflow'))
                <div class="mt-1">{{ $errors->first('workflow') }}</div>
            @endif
        </div>
    </div>
@elseif ($errors->any())
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        @include('partials.icon', ['name' => 'shield', 'size' => 20])
        <div>
            <strong class="d-block mb-1">
                {{ $errors->count() === 1 ? 'Please correct the following' : 'Please correct the following '.$errors->count().' items' }}
            </strong>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
@endonce
