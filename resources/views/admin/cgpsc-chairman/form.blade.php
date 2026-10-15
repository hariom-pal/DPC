@extends('layouts.admin')

@php $isAppointment = ! $chairman->exists; @endphp

@section('title', $isAppointment ? __('admin.cgpsc_chairman.appoint') : __('admin.cgpsc_chairman.heading'))

@section('content')
    {{-- Appointing and amending are the same form with different fields, because
         they are different acts (change register of 2026-08-26, item 9).

         Appointing states who takes office and from when. Amending corrects the
         particulars of whoever already holds it, and deliberately cannot change
         the person or the start date — doing that in place would rewrite who
         chaired every committee composed during the tenure. --}}
    <h1 class="h4 mb-3">
        {{ $isAppointment ? __('admin.cgpsc_chairman.appoint') : __('admin.cgpsc_chairman.heading') }}
    </h1>

    @include('partials.alerts')

    @if ($isAppointment && $serving !== null)
        <div class="alert alert-info small">
            {{ __('admin.cgpsc_chairman.lead') }}
        </div>
    @endif

    <form method="POST"
          action="{{ $isAppointment ? route('admin.cgpsc-chairman.store') : route('admin.cgpsc-chairman.update', $chairman) }}">
        @csrf
        @unless ($isAppointment) @method('PUT') @endunless

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">
                            {{ __('admin.cgpsc_chairman.name') }} <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name', $chairman->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="designation">{{ __('admin.cgpsc_chairman.designation') }}</label>
                        {{-- From the Designation master, never typed
                             (DPC-MODULE-FIX-001, second pass). What is *stored* is
                             still the name: this row is a snapshot, so a committee
                             composed last month keeps naming the Chairman as they
                             were recorded then. --}}
                        @php $selectedDesignation = (int) old('designation_id', $designations->firstWhere('name', $chairman->designation)?->id ?? 0); @endphp
                        <select class="form-select @error('designation_id') is-invalid @enderror"
                                id="designation" name="designation_id"
                                data-searchable data-searchable-placeholder="{{ __('ui.search') }}">
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($designations as $designation)
                                <option value="{{ $designation->id }}" @selected($selectedDesignation === $designation->id)>{{ $designation->name }}</option>
                            @endforeach
                        </select>
                        @error('designation_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="email">{{ __('ui.email') }}</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email', $chairman->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="mobile">{{ __('ui.mobile') }}</label>
                        <input type="text" class="form-control @error('mobile') is-invalid @enderror"
                               id="mobile" name="mobile" value="{{ old('mobile', $chairman->mobile) }}">
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if ($isAppointment)
                        <div class="col-md-6">
                            <label class="form-label" for="user_id">{{ __('ui.user') }}</label>
                            <select class="form-select @error('user_id') is-invalid @enderror" id="user_id" name="user_id">
                                <option value="">{{ __('admin.cgpsc_chairman.none') }}</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}" @selected((int) old('user_id') === $candidate->id)>
                                        {{ $candidate->name }}@if ($candidate->email) — {{ $candidate->email }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">{{ __('admin.cgpsc_chairman.hint_user') }}</div>
                            @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="effective_from">{{ __('admin.cgpsc_chairman.effective_from') }}</label>
                            <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                                   id="effective_from" name="effective_from"
                                   value="{{ old('effective_from', now()->toDateString()) }}">
                            <div class="form-text">{{ __('admin.cgpsc_chairman.hint_effective_from') }}</div>
                            @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-success">
                {{ $isAppointment ? __('admin.cgpsc_chairman.appoint') : __('ui.save') }}
            </button>
            <a href="{{ route('admin.cgpsc-chairman.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
        </div>
    </form>
@endsection
