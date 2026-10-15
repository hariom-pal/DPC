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
                        <label class="form-label" for="department_id">{{ __('ui.department') }}</label>
                        <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id', $roster->department_id) == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="post_id">{{ __('ui.post') }}</label>
                        <select class="form-select @error('post_id') is-invalid @enderror" id="post_id" name="post_id" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($posts as $post)
                                <option value="{{ $post->id }}" @selected(old('post_id', $roster->post_id) == $post->id)>{{ $post->name }}</option>
                            @endforeach
                        </select>
                        @error('post_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="name">{{ __('admin.reservation_rosters.form.roster_name') }}</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $roster->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="size">{{ __('admin.reservation_rosters.form.roster_size') }}</label>
                        <input type="number" min="1" max="1000" class="form-control @error('size') is-invalid @enderror" id="size" name="size" value="{{ old('size', $roster->size) }}" required>
                        @error('size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">{{ __('ui.effective_from') }}</label>
                        <input type="date" class="form-control" id="effective_from" name="effective_from" value="{{ old('effective_from', $roster->effective_from?->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">{{ __('ui.effective_to') }}</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror" id="effective_to" name="effective_to" value="{{ old('effective_to', $roster->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $roster->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.reservation-rosters.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>

    @if ($roster->exists)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                {!! __('admin.reservation_rosters.form.roster_points_heading', ['filled' => $roster->points->count(), 'size' => $roster->size]) !!}

                <form method="POST" action="{{ route('admin.reservation-rosters.points.store', $roster) }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-3">
                        <label class="form-label" for="point_no">{{ __('admin.reservation_rosters.form.point_no') }}</label>
                        <input type="number" min="1" max="{{ $roster->size }}" class="form-control @error('point_no') is-invalid @enderror" id="point_no" name="point_no" required>
                        @error('point_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reservation_category_id">{{ __('ui.category') }}</label>
                        <select class="form-select" id="reservation_category_id" name="reservation_category_id" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->code }} — {{ $category->name_en }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">{{ __('admin.reservation_rosters.form.add_point') }}</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 align-middle">
                        <thead><tr><th>{{ __('admin.reservation_rosters.form.point_no') }}</th><th>{{ __('ui.category') }}</th><th class="text-end">{{ __('ui.action') }}</th></tr></thead>
                        <tbody>
                            @forelse ($roster->points as $point)
                                <tr>
                                    <td>{{ $point->point_no }}</td>
                                    <td>{{ $point->reservationCategory?->code }} — {{ $point->reservationCategory?->name_en }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.reservation-rosters.points.destroy', [$roster, $point]) }}" class="d-inline"
                                              data-confirm="{{ __('admin.reservation_rosters.form.remove_this_point') }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-outline-danger btn-sm">{{ __('ui.remove') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">{{ __('admin.reservation_rosters.form.no_points_defined') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
