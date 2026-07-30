@extends('layouts.admin')

@section('title', $cadre->exists ? 'Edit Cadre' : 'New Cadre')

@section('content')
    <h1 class="h4 mb-3">{{ $cadre->exists ? 'Edit Cadre' : 'New Cadre' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $cadre->exists ? route('admin.cadres.update', $cadre) : route('admin.cadres.store') }}">
                @csrf
                @if ($cadre->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="department_id">Department</label>
                        <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                            <option value="">— Select —</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id', $cadre->department_id) == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="name">Cadre / Post Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $cadre->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="class_level">Service Class</label>
                        <select class="form-select @error('class_level') is-invalid @enderror" id="class_level" name="class_level" required>
                            @foreach ($classes as $class)
                                <option value="{{ $class->value }}" @selected(old('class_level', $cadre->class_level?->value) === $class->value)>{{ $class->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="pay_scale_id">Pay Scale</label>
                        <select class="form-select" id="pay_scale_id" name="pay_scale_id">
                            <option value="">— None —</option>
                            @foreach ($payScales as $payScale)
                                <option value="{{ $payScale->id }}" @selected(old('pay_scale_id', $cadre->pay_scale_id) == $payScale->id)>{{ $payScale->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $cadre->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.cadres.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
