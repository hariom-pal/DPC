@extends('layouts.portal')
@section('title', __('candidate.grievance.heading', ['section' => $section->label()]))
@section('content')
    @include('candidate-verification.partials.shell-start', ['signedIn' => true])
    <div class="gov-card p-3">
        <h2 class="h6">{{ __('candidate.grievance.heading', ['section' => $section->label()]) }}</h2>
        <form method="POST" action="{{ route('candidate-verification.grievance.save', [$participant->uuid, $section->value]) }}" enctype="multipart/form-data" id="grievance-form">
            @csrf
            @if ($grievance)<input type="hidden" name="grievance" value="{{ $grievance->uuid }}">@endif
            <div class="mb-2">
                <label class="form-label small" for="category">{{ __('candidate.grievance.category') }}</label>
                <select class="form-select form-select-sm" id="category" name="category" required>
                    @foreach ($section->categories() as $category)
                        <option value="{{ $category }}" @selected(old('category', $grievance?->category) === $category)>{{ __('candidate.grievance.categories.'.$category) }}</option>
                    @endforeach
                </select>
            </div>
            @if ($years !== [])
                <div class="mb-2">
                    <label class="form-label small" for="related_year">{{ __('candidate.grievance.year') }}</label>
                    <select class="form-select form-select-sm" id="related_year" name="related_year">
                        <option value="">—</option>
                        @foreach ($years as $year)
                            <option value="{{ $year }}" @selected((int) old('related_year', $grievance?->related_year) === $year)>{{ $year }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="mb-2">
                <label class="form-label small" for="remark">{{ __('candidate.grievance.remark') }}</label>
                <textarea class="form-control form-control-sm" id="remark" name="remark" rows="4" maxlength="2000" required>{{ old('remark', $grievance?->remark) }}</textarea>
            </div>
            <div class="mb-2">
                <label class="form-label small" for="document">{{ __('candidate.grievance.document') }}</label>
                <input type="file" class="form-control form-control-sm" id="document" name="document" accept="application/pdf" @if (! $grievance) required @endif>
                @if ($grievance)<div class="form-text">{{ __('candidate.grievance.document_kept', ['name' => $grievance->original_name]) }}</div>@endif
            </div>
            <div class="border rounded p-2 mb-2 small" id="undertaking">
                <strong>{{ __('candidate.grievance.undertaking_label') }}</strong>
                <p class="mb-1">{{ __('candidate.grievance.undertaking', [], 'en') }}</p>
                <p class="mb-1">{{ __('candidate.grievance.undertaking_hi') }}</p>
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="undertaking" value="1" required>
                    <span class="form-check-label">{{ __('candidate.grievance.accept') }}</span>
                </label>
            </div>
            <button class="btn btn-warning">{{ __('candidate.grievance.save_next') }}</button>
            <a class="btn btn-link" href="{{ route('candidate-verification.section', [$participant->uuid, $section->value]) }}">{{ __('candidate.verification.actions.back') }}</a>
        </form>
    </div>
    @include('candidate-verification.partials.shell-end')
@endsection
