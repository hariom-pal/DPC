@extends('layouts.admin')

@section('title', $department->exists ? 'Edit Department' : 'New Department')

@section('content')
    <h1 class="h4 mb-3">{{ $department->exists ? 'Edit Department' : 'New Department' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $department->exists ? route('admin.departments.update', $department) : route('admin.departments.store') }}">
                @csrf
                @if ($department->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input type="text" class="form-control text-uppercase @error('code') is-invalid @enderror"
                               id="code" name="code" value="{{ old('code', $department->code) }}"
                               maxlength="20" required @disabled($codeLocked)>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            @if ($codeLocked)
                                Locked — DPC IDs have already been issued under this code.
                            @else
                                Becomes the DPC ID prefix, e.g. <code>SE</code> → <code>SE202600001</code>.
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name">Department Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name', $department->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}"
                                    @selected(old('status', $department->status?->value ?? 'unregistered') === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Only <em>Unregistered</em> departments appear on the registration screen.</div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="district_id">District</label>
                        <select class="form-select @error('district_id') is-invalid @enderror" id="district_id" name="district_id">
                            <option value="">— Not set —</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->id }}"
                                    @selected((int) old('district_id', $department->district_id) === $district->id)>
                                    {{ $district->name_en }}
                                </option>
                            @endforeach
                        </select>
                        @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="place">Place</label>
                        <input type="text" class="form-control @error('place') is-invalid @enderror"
                               id="place" name="place" value="{{ old('place', $department->place) }}">
                        @error('place')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="pin">PIN</label>
                        <input type="text" class="form-control @error('pin') is-invalid @enderror"
                               id="pin" name="pin" value="{{ old('pin', $department->pin) }}" maxlength="6">
                        @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="email">Departmental Contact Email</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email', $department->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Captured at registration; editable here for corrections.</div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.departments.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
