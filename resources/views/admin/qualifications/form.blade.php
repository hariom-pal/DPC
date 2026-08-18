@extends('layouts.admin')
@section('title', 'Qualification')
@section('content')
    <h1 class="h4 mb-3">{{ $qualification->exists ? 'Edit Qualification' : 'New Qualification' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $qualification->exists ? route('admin.qualifications.update', $qualification) : route('admin.qualifications.store') }}">
                @csrf
                @if ($qualification->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">Qualification Code</label>
                        <input type="text" class="form-control @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $qualification->code) }}" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Short, stable key — e.g. <code>BED</code>. Used to match legacy free text.</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name_en">Qualification Name (English)</label>
                        <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                               id="name_en" name="name_en" value="{{ old('name_en', $qualification->name_en) }}" required>
                        @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="name_hi">अर्हता का नाम (Hindi)</label>
                        <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                               id="name_hi" name="name_hi" value="{{ old('name_hi', $qualification->name_hi) }}" required>
                        @error('name_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $qualification->status?->value ?? 'draft') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Only <strong>Active</strong> appears in the Recruitment Rule dropdown.</div>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control @error('description') is-invalid @enderror"
                                  id="description" name="description" rows="2"
                                  maxlength="1000">{{ old('description', $qualification->description) }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.qualifications.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
