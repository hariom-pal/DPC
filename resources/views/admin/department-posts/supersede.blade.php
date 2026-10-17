@extends('layouts.admin')

@section('title', __('admin.department_posts.supersede.title'))

@section('content')
    <h1 class="h4 mb-1">{{ __('admin.department_posts.supersede.title') }}</h1>
    <p class="text-muted small mb-3">{{ __('admin.department_posts.supersede.lead') }}</p>

    @include('partials.alerts')

    {{-- What is being replaced, stated before the form. The Administrator is about
         to close a mapping that proposals already reference, and they should see
         exactly which one. --}}
    <div class="alert alert-secondary small">
        <strong class="d-block mb-1">{{ __('admin.department_posts.supersede.replacing') }}</strong>
        {{ $post->department?->name ?? '—' }} · {{ $post->name }} ·
        {{ $post->class_level?->label() ?? '—' }} ·
        {{ $post->payScale?->label ?? __('ui.none') }} ·
        {{ $post->promotion_duration_years === null ? '—' : __('ui.n_years', ['count' => $post->promotion_duration_years]) }}
        <br>
        {{ __('admin.department_posts.form.go_number') }}: {{ $post->governmentOrderLabel() }} ·
        {{ __('admin.department_posts.form.effective_from') }}: {{ $post->effective_from?->format('d-m-Y') ?? '—' }}
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.department-posts.supersede', $post) }}">
                @csrf

                {{-- Department and post name are deliberately absent: a supersession
                     re-authorises the same post on new terms, and they are read from
                     the mapping being replaced. --}}
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="class_level">{{ __('admin.department_posts.form.service_class') }} <span class="text-danger">*</span></label>
                        <select class="form-select @error('class_level') is-invalid @enderror" id="class_level" name="class_level" required>
                            @foreach ($classes as $class)
                                <option value="{{ $class->value }}" @selected(old('class_level', $post->class_level?->value) === $class->value)>{{ $class->label() }}</option>
                            @endforeach
                        </select>
                        @error('class_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="pay_scale_id">{{ __('ui.pay_scale') }}</label>
                        <select class="form-select" id="pay_scale_id" name="pay_scale_id">
                            <option value="">{{ __('ui.none') }}</option>
                            @foreach ($payScales as $payScale)
                                <option value="{{ $payScale->id }}" @selected(old('pay_scale_id', $post->pay_scale_id) == $payScale->id)>{{ $payScale->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="promotion_duration_years">{{ __('admin.department_posts.form.promotion_duration_years') }}</label>
                        <input type="number" min="0" max="40" step="1"
                               class="form-control @error('promotion_duration_years') is-invalid @enderror"
                               id="promotion_duration_years" name="promotion_duration_years"
                               value="{{ old('promotion_duration_years', $post->promotion_duration_years) }}">
                        @error('promotion_duration_years')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">{{ __('admin.department_posts.form.effective_from') }} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                               id="effective_from" name="effective_from"
                               value="{{ old('effective_from') }}" required>
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.department_posts.supersede.hint_effective_from') }}</div>
                    </div>
                </div>

                <h2 class="h6 text-uppercase text-body-secondary border-bottom pb-2 mb-3 mt-2">
                    {{ __('admin.department_posts.form.authority') }}
                </h2>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="government_order_number">{{ __('admin.department_posts.form.go_number') }} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('government_order_number') is-invalid @enderror"
                               id="government_order_number" name="government_order_number"
                               value="{{ old('government_order_number') }}" required>
                        @error('government_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="government_order_date">{{ __('admin.department_posts.form.go_date') }} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('government_order_date') is-invalid @enderror"
                               id="government_order_date" name="government_order_date"
                               value="{{ old('government_order_date') }}" required>
                        @error('government_order_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="government_order_document">{{ __('admin.department_posts.form.go_document') }}</label>
                        <input type="file" accept="application/pdf"
                               class="form-control @error('government_order_document') is-invalid @enderror"
                               id="government_order_document" name="government_order_document">
                        @error('government_order_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.department_posts.form.hint_go_document') }}</div>
                    </div>
                </div>

                <div class="alert alert-warning small">{{ __('admin.department_posts.supersede.what_happens') }}</div>

                <button type="submit" class="btn btn-warning">{{ __('admin.department_posts.supersede.confirm') }}</button>
                <a href="{{ route('admin.department-posts.show', $post) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
