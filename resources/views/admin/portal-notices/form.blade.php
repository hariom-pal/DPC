@extends('layouts.admin')

@section('title', __($notice->exists ? 'admin.portal_notices.form.title_edit' : 'admin.portal_notices.form.title_new'))

@section('content')
    <h1 class="h4 mb-3">{{ __($notice->exists ? 'admin.portal_notices.form.title_edit' : 'admin.portal_notices.form.title_new') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $notice->exists ? route('admin.portal-notices.update', $notice) : route('admin.portal-notices.store') }}">
                @csrf
                @if ($notice->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="title_en">{{ __('admin.portal_notices.form.title_en') }} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('title_en') is-invalid @enderror"
                               id="title_en" name="title_en" value="{{ old('title_en', $notice->title_en) }}" required>
                        @error('title_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="title_hi">{{ __('admin.portal_notices.form.title_hi') }}</label>
                        <input type="text" class="form-control @error('title_hi') is-invalid @enderror"
                               id="title_hi" name="title_hi" value="{{ old('title_hi', $notice->title_hi) }}">
                        @error('title_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.portal_notices.form.hint_hindi') }}</div>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="body_en">{{ __('admin.portal_notices.form.body_en') }} <span class="text-danger">*</span></label>
                        <textarea class="form-control @error('body_en') is-invalid @enderror" rows="4"
                                  id="body_en" name="body_en" required>{{ old('body_en', $notice->body_en) }}</textarea>
                        @error('body_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="body_hi">{{ __('admin.portal_notices.form.body_hi') }}</label>
                        <textarea class="form-control @error('body_hi') is-invalid @enderror" rows="4"
                                  id="body_hi" name="body_hi">{{ old('body_hi', $notice->body_hi) }}</textarea>
                        @error('body_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="published_from">{{ __('admin.portal_notices.form.published_from') }} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('published_from') is-invalid @enderror"
                               id="published_from" name="published_from"
                               value="{{ old('published_from', $notice->published_from?->format('Y-m-d')) }}" required>
                        @error('published_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="published_until">{{ __('admin.portal_notices.form.published_until') }}</label>
                        <input type="date" class="form-control @error('published_until') is-invalid @enderror"
                               id="published_until" name="published_until"
                               value="{{ old('published_until', $notice->published_until?->format('Y-m-d')) }}">
                        @error('published_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.portal_notices.form.hint_until') }}</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="sort_order">{{ __('ui.sort_order') }}</label>
                        <input type="number" min="0" max="9999" class="form-control @error('sort_order') is-invalid @enderror"
                               id="sort_order" name="sort_order" value="{{ old('sort_order', $notice->sort_order ?? 0) }}">
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }} <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $notice->status?->value ?? 'active') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                    <a href="{{ route('admin.portal-notices.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
@endsection
