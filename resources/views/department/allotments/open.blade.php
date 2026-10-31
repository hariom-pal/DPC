{{-- Open a Pre-DPC (A1, 2026-10-26): the exercise and its AO and AH. The allotted AO
     fills in everything else. --}}
@extends('layouts.app')
@section('title', __('allotments.open'))
@section('content')
    <h1 class="h4 mb-1">{{ __('allotments.open') }}</h1>
    <p class="text-muted small mb-3">{{ __('allotments.open_lead') }}</p>

    @include('partials.alerts')

    <form method="POST" action="{{ route('department.allotments.pre-dpc.store') }}" class="card border-0 shadow-sm">
        @csrf
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="dpc_year" class="form-label">{{ __('allotments.field.dpc_year') }}</label>
                <input type="number" id="dpc_year" name="dpc_year" value="{{ old('dpc_year', now()->year) }}" required min="{{ config('dpc.earliest_dpc_year', 2019) }}" max="{{ now()->addYear()->year }}" step="1" inputmode="numeric" data-whole-number
                       class="form-control @error('dpc_year') is-invalid @enderror">
                @error('dpc_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-8">
                <label for="promotion_to_post_id" class="form-label">{{ __('allotments.field.promotion_to_post') }}</label>
                <select id="promotion_to_post_id" name="promotion_to_post_id" required class="form-select @error('promotion_to_post_id') is-invalid @enderror">
                    <option value="">{{ __('allotments.field.choose_post') }}</option>
                    @foreach ($posts as $post)
                        <option value="{{ $post->id }}" @selected((int) old('promotion_to_post_id') === $post->id)>{{ $post->name }}</option>
                    @endforeach
                </select>
                @error('promotion_to_post_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 small text-muted">{{ __('allotments.assign_last') }}</div>
        </div>
        <div class="card-footer bg-transparent d-flex gap-2">
            <button type="submit" class="btn btn-primary">{{ __('allotments.open_submit') }}</button>
            <a href="{{ route('department.allotments.index', ['view' => 'pre-dpc']) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
        </div>
    </form>
@endsection
