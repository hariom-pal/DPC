{{-- Allot, or reassign with a reason (A5, 2026-10-26). --}}
@extends('layouts.app')
@section('title', $current ? __('allotments.reassign') : __('allotments.allot'))
@section('content')
    <h1 class="h4 mb-1">{{ ($current ? __('allotments.reassign') : __('allotments.allot')).' — '.$label }}</h1>
    @if ($current)
        <p class="text-muted small mb-3">{{ __('allotments.currently', ['ao' => $current->ao->name, 'ah' => $current->ah->name, 'date' => $current->allotted_at->format('d-m-Y H:i')]) }}</p>
    @else
        <p class="small mb-3"><span class="badge text-bg-warning">{{ __('allotments.pending') }}</span> {{ __('allotments.pending_lead') }}</p>
    @endif

    @include('partials.alerts')
    @include('partials.esign-issue', ['proposal' => $proposal])

    <form method="POST" action="{{ $action }}" class="card border-0 shadow-sm">
        @csrf
        @method('PUT')
        <div class="card-body row g-3">
            @include('department.allotments._officers', ['current' => $current])
            @if ($current)
                <div class="col-12">
                    <label for="reason" class="form-label">{{ __('allotments.field.reason') }}</label>
                    <textarea id="reason" name="reason" rows="2" required maxlength="1000"
                              class="form-control @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
                    <div class="form-text">{{ __('allotments.reason_help') }}</div>
                </div>
            @endif
        </div>
        <div class="card-footer bg-transparent d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">{{ $current ? __('allotments.reassign') : __('allotments.allot') }}</button>
            @if ($proposal instanceof \App\Domain\PreDpc\Models\PreDpcProposal && $proposal->status->isEditable())
                <a href="{{ route('pre-dpc.edit', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('allotments.edit_pre_dpc') }}</a>
            @endif
            <a href="{{ route('department.allotments.index', $proposal instanceof \App\Domain\PreDpc\Models\PreDpcProposal ? ['view' => 'pre-dpc'] : []) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.cancel') }}</a>
        </div>
    </form>

    {{-- The other remedy for an eSign whose name did not match (2026-10-28):
         correct the officer's details, with a reason. --}}
    @if ($current)
        <h2 class="h6 mt-4">{{ __('allotments.details.title') }}</h2>
        <p class="small text-muted">{{ __('allotments.details.lead') }}</p>
        @error('officer_details')<div class="alert alert-danger small">{{ $message }}</div>@enderror
        <div class="row g-3">
            @foreach (['ao' => $current->ao, 'ah' => $current->ah] as $slot => $officer)
                <div class="col-md-6">
                    <form method="POST" action="{{ route('department.officers.details', $officer) }}" class="card border-0 shadow-sm" id="details-{{ $slot }}">
                        @csrf
                        @method('PUT')
                        <div class="card-body small row g-2">
                            <div class="col-12 fw-semibold">{{ __('allotments.column.'.$slot) }} — {{ $officer->email }}</div>
                            @if ($officer->identity_verified_at)
                                <div class="col-12 text-muted">{{ __('identity.errors.locked') }}</div>
                            @else
                                <div class="col-12"><label class="form-label">{{ __('ui.name') }}</label>
                                    <input class="form-control form-control-sm" name="name" value="{{ $officer->name }}" required maxlength="255"></div>
                                <div class="col-6"><label class="form-label">{{ __('identity.field.date_of_birth') }}</label>
                                    <input type="date" class="form-control form-control-sm" name="date_of_birth" value="{{ $officer->date_of_birth?->toDateString() }}"></div>
                                <div class="col-6"><label class="form-label">{{ __('identity.field.gender') }}</label>
                                    <select class="form-select form-select-sm" name="gender">
                                        <option value="">{{ __('ui.select') }}</option>
                                        @foreach (['M', 'F'] as $gender)
                                            <option value="{{ $gender }}" @selected($officer->gender === $gender)>{{ __('identity.gender.'.$gender) }}</option>
                                        @endforeach
                                    </select></div>
                                <div class="col-12"><label class="form-label">{{ __('department.users.designation') }}</label>
                                    <select class="form-select form-select-sm" name="designation_id">
                                        <option value="">{{ __('ui.select') }}</option>
                                        @foreach ($designations as $designation)
                                            <option value="{{ $designation->id }}" @selected($officer->designation_id === $designation->id)>{{ $designation->name }}</option>
                                        @endforeach
                                    </select></div>
                                <div class="col-12"><label class="form-label">{{ __('allotments.details.reason') }}</label>
                                    <input class="form-control form-control-sm" name="reason" required maxlength="500"></div>
                                <div class="col-12"><button class="btn btn-outline-primary btn-sm">{{ __('allotments.details.save') }}</button></div>
                            @endif
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
@endsection
