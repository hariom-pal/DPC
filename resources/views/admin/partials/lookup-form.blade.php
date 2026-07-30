<h1 class="h4 mb-3">{{ $model->exists ? 'Edit '.$title : 'New '.$title }}</h1>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ $model->exists ? route($routeBase.'.update', $model) : route($routeBase.'.store') }}">
            @csrf
            @if ($model->exists) @method('PUT') @endif

            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="code">Code</label>
                    <input type="text" class="form-control @error('code') is-invalid @enderror"
                           id="code" name="code" value="{{ old('code', $model->code) }}" required>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="name_en">Name (English)</label>
                    <input type="text" class="form-control @error('name_en') is-invalid @enderror"
                           id="name_en" name="name_en" value="{{ old('name_en', $model->name_en) }}" required>
                    @error('name_en')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="name_hi">नाम (Hindi)</label>
                    <input type="text" class="form-control @error('name_hi') is-invalid @enderror"
                           id="name_hi" name="name_hi" value="{{ old('name_hi', $model->name_hi) }}" required>
                    @error('name_hi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}"
                                @selected(old('status', $model->status?->value ?? 'draft') === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{ $slot ?? '' }}

            <button type="submit" class="btn btn-success">Save</button>
            <a href="{{ route($routeBase.'.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
