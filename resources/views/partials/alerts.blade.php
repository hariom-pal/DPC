{{-- Shared flash + validation display.

     Presentation only — the same session keys and the same $errors bag as
     before. Icons are inline SVG so an alert never renders as a broken glyph. --}}

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

@if ($errors->any())
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
