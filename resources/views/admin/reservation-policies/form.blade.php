@extends('layouts.admin')

@section('title', $policy->exists ? 'Edit Reservation Policy' : 'New Reservation Policy')

@section('content')
    <h1 class="h4 mb-3">{{ $policy->exists ? 'Edit Reservation Policy' : 'New Reservation Policy' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $policy->exists ? route('admin.reservation-policies.update', $policy) : route('admin.reservation-policies.store') }}">
                @csrf
                @if ($policy->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="reservation_category_id">Reservation Category</label>
                        <select class="form-select @error('reservation_category_id') is-invalid @enderror" id="reservation_category_id" name="reservation_category_id" required>
                            <option value="">— Select —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('reservation_category_id', $policy->reservation_category_id) == $category->id)>{{ $category->code }} — {{ $category->name_en }}</option>
                            @endforeach
                        </select>
                        @error('reservation_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="percentage">Percentage (%)</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control @error('percentage') is-invalid @enderror" id="percentage" name="percentage" value="{{ old('percentage', $policy->percentage) }}" required>
                        @error('percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $policy->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">Effective From</label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror" id="effective_from" name="effective_from"
                               value="{{ old('effective_from', $policy->effective_from?->format('Y-m-d')) }}" required>
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">Effective To</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror" id="effective_to" name="effective_to"
                               value="{{ old('effective_to', $policy->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.reservation-policies.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
