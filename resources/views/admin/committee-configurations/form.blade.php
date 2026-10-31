@extends('layouts.admin')

@section('title', __($configuration->exists ? 'admin.committee_rules.title_edit' : 'admin.committee_rules.title_new'))

@section('content')
    @php
        $c = $configuration;
        $chosen = old('required_categories', $c->exists ? ($c->required_categories ?? []) : (array) config('dpc.committee.required_categories', []));
    @endphp

    <h1 class="h4 mb-1">{{ __($c->exists ? 'admin.committee_rules.title_edit' : 'admin.committee_rules.title_new') }}</h1>
    <p class="text-muted small mb-3">{{ __('admin.committee_rules.intro') }}</p>

    <form method="POST" action="{{ $c->exists ? route('admin.committee-configurations.update', $c) : route('admin.committee-configurations.store') }}">
        @csrf
        @if ($c->exists) @method('PUT') @endif

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>1. {{ __('admin.committee_rules.chairman_heading') }}</strong></div>
            <div class="card-body small">{{ __('admin.committee_rules.chairman_fixed') }}</div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>2. {{ __('admin.committee_rules.size_heading') }}</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="members_min">{{ __('admin.committee_rules.members_min') }}</label>
                        <input type="number" min="1" max="50" class="form-control @error('members_min') is-invalid @enderror" id="members_min" name="members_min"
                               value="{{ old('members_min', $c->exists ? $c->min_members - 1 : config('dpc.committee.min_members', 3)) }}" required @error('members_min') autofocus @enderror>
                        @error('members_min')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="members_max">{{ __('admin.committee_rules.members_max') }}</label>
                        <input type="number" min="1" max="50" class="form-control @error('members_max') is-invalid @enderror" id="members_max" name="members_max"
                               value="{{ old('members_max', $c->exists ? $c->max_members - 1 : config('dpc.committee.max_members', 6)) }}" required @error('members_max') autofocus @enderror>
                        @error('members_max')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12"><div class="form-text">{{ __('admin.committee_rules.size_help') }}</div></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>3. {{ __('admin.committee_rules.composition_heading') }}</strong></div>
            <div class="card-body">
                <p class="small text-muted">{{ __('admin.committee_rules.composition_help') }}</p>
                <div class="row">
                    <div class="col-md-6">
                        <div class="small fw-semibold mb-1">{{ __('admin.committee_rules.social_heading') }}</div>
                        @foreach ($socialCategories as $category)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="required_categories[]" value="{{ $category->value }}" id="cat_{{ $category->value }}" @checked(in_array($category->value, $chosen, true))>
                                <label class="form-check-label" for="cat_{{ $category->value }}">{{ $category->label() }}</label>
                            </div>
                        @endforeach
                        <div class="form-text">{{ __('admin.committee_rules.social_help') }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small fw-semibold mb-1">{{ __('admin.committee_rules.additional_heading') }}</div>
                        @foreach ($additionalAttributes as $category)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="required_categories[]" value="{{ $category->value }}" id="cat_{{ $category->value }}" @checked(in_array($category->value, $chosen, true))>
                                <label class="form-check-label" for="cat_{{ $category->value }}">{{ $category->label() }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
                @error('required_categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                @error('required_categories.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" value="1" id="requires_reserved_category" name="requires_reserved_category"
                           @checked(old('requires_reserved_category', $c->exists ? $c->requires_reserved_category : config('dpc.committee.require_reserved_category', false)))>
                    <label class="form-check-label" for="requires_reserved_category">{{ __('admin.committee_rules.requires_reserved_category') }}</label>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white"><strong>4. {{ __('admin.committee_rules.when_heading') }}</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="effective_from">{{ __('admin.committee_rules.effective_from') }}</label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror" id="effective_from" name="effective_from" value="{{ old('effective_from', $c->effective_from?->toDateString()) }}">
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="effective_to">{{ __('admin.committee_rules.effective_to') }}</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror" id="effective_to" name="effective_to" value="{{ old('effective_to', $c->effective_to?->toDateString()) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $c->status?->value ?? 'active') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12"><div class="form-text">{{ __('admin.committee_rules.when_help') }}</div></div>
                </div>
            </div>
        </div>

        <button class="btn btn-success">{{ __('ui.save') }}</button>
        <a href="{{ route('admin.committee-configurations.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
    </form>
@endsection
