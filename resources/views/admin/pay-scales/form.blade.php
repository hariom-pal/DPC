@extends('layouts.admin')

@section('title', $payScale->exists ? 'Edit Pay Scale' : 'New Pay Scale')

@section('content')
    <h1 class="h4 mb-3">{{ $payScale->exists ? 'Edit Pay Scale' : 'New Pay Scale' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $payScale->exists ? route('admin.pay-scales.update', $payScale) : route('admin.pay-scales.store') }}">
                @csrf
                @if ($payScale->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="label">Label</label>
                        <input type="text" class="form-control @error('label') is-invalid @enderror"
                               id="label" name="label" value="{{ old('label', $payScale->label) }}" required>
                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input type="text" class="form-control @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $payScale->code) }}">
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $payScale->status?->value ?? 'draft') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.pay-scales.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
