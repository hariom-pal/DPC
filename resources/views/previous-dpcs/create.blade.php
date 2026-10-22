@extends('layouts.app')
@section('title', __('previous_dpc.new'))
@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('previous-dpcs.index') }}">{{ __('previous_dpc.title') }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('previous_dpc.new') }}</li>
        </ol>
    </nav>
    <h1 class="h4 mb-1">{{ __('previous_dpc.new') }}</h1>
    <p class="text-muted small">{{ __('previous_dpc.form_lead', ['count' => $uploadsRequiredFrom]) }}</p>
    @error('record')<div class="alert alert-danger">{{ $message }}</div>@enderror

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('previous-dpcs.store') }}" enctype="multipart/form-data" class="row g-3">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="department_id">{{ __('previous_dpc.fields.department_id') }}</label>
                    <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                        <option value="">{{ __('ui.select') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((int) old('department_id') === $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @foreach (['promotion_from_post_id' => false, 'promotion_to_post_id' => true] as $field => $required)
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $field }}">{{ __('previous_dpc.fields.'.$field) }}</label>
                        <select class="form-select @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" @required($required)>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($posts as $post)
                                <option value="{{ $post->id }}" data-department="{{ $post->department_id }}" @selected((int) old($field) === $post->id)>{{ $post->name }}</option>
                            @endforeach
                        </select>
                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                @foreach ([['dpc_year', 'number', true], ['held_on', 'date', true], ['reference', 'text', true], ['order_number', 'text', false], ['order_date', 'date', false], ['eligible_count', 'number', true]] as [$field, $type, $required])
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $field }}">{{ __('previous_dpc.fields.'.$field) }}</label>
                        <input type="{{ $type }}" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" @required($required)>
                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="col-12">
                    <label class="form-label" for="promoted_officers">{{ __('previous_dpc.fields.promoted_officers') }}</label>
                    <textarea class="form-control @error('promoted_officers') is-invalid @enderror" id="promoted_officers" name="promoted_officers" rows="5" required>{{ old('promoted_officers') }}</textarea>
                    <div class="form-text">{{ __('previous_dpc.officers_help') }}</div>
                    @error('promoted_officers')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @foreach (['minutes', 'seniority_list', 'working_officers'] as $file)
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $file }}">{{ __('previous_dpc.fields.'.$file) }}</label>
                        <input type="file" accept="application/pdf" class="form-control @error($file) is-invalid @enderror" id="{{ $file }}" name="{{ $file }}" @required($file === 'minutes')>
                        @if ($file !== 'minutes')<div class="form-text">{{ __('previous_dpc.optional_below', ['count' => $uploadsRequiredFrom]) }}</div>@endif
                        @error($file)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <div class="col-12">
                    <label class="form-label" for="remarks">{{ __('ui.remarks') }}</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-success">{{ __('ui.save') }}</button>
                    <a href="{{ route('previous-dpcs.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
@endsection
