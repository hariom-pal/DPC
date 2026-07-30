@extends('layouts.admin')

@section('title', $roster->exists ? 'Edit Roster' : 'New Roster')

@section('content')
    <h1 class="h4 mb-3">{{ $roster->exists ? 'Edit Reservation Roster' : 'New Reservation Roster' }}</h1>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="{{ $roster->exists ? route('admin.reservation-rosters.update', $roster) : route('admin.reservation-rosters.store') }}">
                @csrf
                @if ($roster->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="department_id">Department</label>
                        <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                            <option value="">— Select —</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id', $roster->department_id) == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="cadre_id">Cadre</label>
                        <select class="form-select @error('cadre_id') is-invalid @enderror" id="cadre_id" name="cadre_id" required>
                            <option value="">— Select —</option>
                            @foreach ($cadres as $cadre)
                                <option value="{{ $cadre->id }}" @selected(old('cadre_id', $roster->cadre_id) == $cadre->id)>{{ $cadre->name }}</option>
                            @endforeach
                        </select>
                        @error('cadre_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name">Roster Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $roster->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="size">Roster Size</label>
                        <input type="number" min="1" max="1000" class="form-control @error('size') is-invalid @enderror" id="size" name="size" value="{{ old('size', $roster->size) }}" required>
                        @error('size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">Effective From</label>
                        <input type="date" class="form-control" id="effective_from" name="effective_from" value="{{ old('effective_from', $roster->effective_from?->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">Effective To</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror" id="effective_to" name="effective_to" value="{{ old('effective_to', $roster->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $roster->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.reservation-rosters.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>

    @if ($roster->exists)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Roster Points ({{ $roster->points->count() }} / {{ $roster->size }})</h2>

                <form method="POST" action="{{ route('admin.reservation-rosters.points.store', $roster) }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label" for="point_no">Point No.</label>
                        <input type="number" min="1" max="{{ $roster->size }}" class="form-control @error('point_no') is-invalid @enderror" id="point_no" name="point_no" required>
                        @error('point_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reservation_category_id">Category</label>
                        <select class="form-select" id="reservation_category_id" name="reservation_category_id" required>
                            <option value="">— Select —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->code }} — {{ $category->name_en }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Add Point</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 align-middle">
                        <thead><tr><th>Point No.</th><th>Category</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            @forelse ($roster->points as $point)
                                <tr>
                                    <td>{{ $point->point_no }}</td>
                                    <td>{{ $point->reservationCategory?->code }} — {{ $point->reservationCategory?->name_en }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.reservation-rosters.points.destroy', [$roster, $point]) }}" class="d-inline"
                                              onsubmit="return confirm('Remove this point?');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">No points defined.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
