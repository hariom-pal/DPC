@extends('layouts.admin')

@section('title', $rule->exists ? 'Edit Recruitment Rule' : 'New Recruitment Rule')

@section('content')
    <h1 class="h4 mb-3">{{ $rule->exists ? 'Edit Recruitment Rule (v'.$rule->version.')' : 'New Recruitment Rule' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $rule->exists ? route('admin.recruitment-rules.update', $rule) : route('admin.recruitment-rules.store') }}">
                @csrf
                @if ($rule->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-5 mb-3">
                        <label class="form-label" for="cadre_id">Target Cadre / Post</label>
                        <select class="form-select @error('cadre_id') is-invalid @enderror" id="cadre_id" name="cadre_id" required>
                            <option value="">— Select —</option>
                            @foreach ($cadres as $cadre)
                                <option value="{{ $cadre->id }}" @selected(old('cadre_id', $rule->cadre_id) == $cadre->id)>{{ $cadre->name }} ({{ $cadre->class_level->label() }})</option>
                            @endforeach
                        </select>
                        @error('cadre_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="government_order_number">Government Order No.</label>
                        <input type="text" class="form-control @error('government_order_number') is-invalid @enderror" id="government_order_number" name="government_order_number" value="{{ old('government_order_number', $rule->government_order_number) }}" required>
                        @error('government_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $rule->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="direct_recruitment_pct">Direct Recruitment %</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control @error('direct_recruitment_pct') is-invalid @enderror" id="direct_recruitment_pct" name="direct_recruitment_pct" value="{{ old('direct_recruitment_pct', $rule->direct_recruitment_pct ?? 0) }}" required>
                        @error('direct_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="promotion_recruitment_pct">Promotion Recruitment %</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control @error('promotion_recruitment_pct') is-invalid @enderror" id="promotion_recruitment_pct" name="promotion_recruitment_pct" value="{{ old('promotion_recruitment_pct', $rule->promotion_recruitment_pct ?? 0) }}" required>
                        @error('promotion_recruitment_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">Effective From</label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror" id="effective_from" name="effective_from" value="{{ old('effective_from', $rule->effective_from?->format('Y-m-d')) }}" required>
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">Effective To</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror" id="effective_to" name="effective_to" value="{{ old('effective_to', $rule->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="go_document">Government Order PDF</label>
                        <input type="file" accept="application/pdf" class="form-control @error('go_document') is-invalid @enderror" id="go_document" name="go_document">
                        @if ($rule->go_document_path)
                            <div class="form-text">Current: <a href="{{ route('admin.recruitment-rules.download', $rule) }}">GO PDF</a> (uploading replaces it)</div>
                        @endif
                        @error('go_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 mb-0">Feeder Cadres (Schedule-IV)</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="add-feeder">+ Add Feeder</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="feeders-table">
                        <thead><tr><th>Feeder Cadre</th><th>Promotion %</th><th>Min Experience (yrs)</th><th>Min Qualification</th><th></th></tr></thead>
                        <tbody>
                            @php($rows = old('feeders', $rule->feeders?->map(fn ($f) => [
                                'feeder_cadre_id' => $f->feeder_cadre_id,
                                'promotion_percentage' => $f->promotion_percentage,
                                'min_experience_years' => $f->min_experience_years,
                                'min_qualification' => $f->min_qualification,
                            ])->all() ?? []))
                            @forelse ($rows as $i => $row)
                                <tr>
                                    <td>
                                        <select class="form-select form-select-sm" name="feeders[{{ $i }}][feeder_cadre_id]" required>
                                            <option value="">— Select —</option>
                                            @foreach ($cadres as $cadre)
                                                <option value="{{ $cadre->id }}" @selected(($row['feeder_cadre_id'] ?? null) == $cadre->id)>{{ $cadre->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="feeders[{{ $i }}][promotion_percentage]" value="{{ $row['promotion_percentage'] ?? 0 }}" required></td>
                                    <td><input type="number" min="0" max="60" class="form-control form-control-sm" name="feeders[{{ $i }}][min_experience_years]" value="{{ $row['min_experience_years'] ?? 0 }}" required></td>
                                    <td><input type="text" class="form-control form-control-sm" name="feeders[{{ $i }}][min_qualification]" value="{{ $row['min_qualification'] ?? '' }}"></td>
                                    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm remove-feeder">×</button></td>
                                </tr>
                            @empty
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('admin.recruitment-rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>

    <template id="feeder-row-template">
        <tr>
            <td>
                <select class="form-select form-select-sm" name="feeders[__INDEX__][feeder_cadre_id]" required>
                    <option value="">— Select —</option>
                    @foreach ($cadres as $cadre)
                        <option value="{{ $cadre->id }}">{{ $cadre->name }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" name="feeders[__INDEX__][promotion_percentage]" value="0" required></td>
            <td><input type="number" min="0" max="60" class="form-control form-control-sm" name="feeders[__INDEX__][min_experience_years]" value="0" required></td>
            <td><input type="text" class="form-control form-control-sm" name="feeders[__INDEX__][min_qualification]"></td>
            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm remove-feeder">×</button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
    (function () {
        let index = {{ is_countable($rows) ? count($rows) : 0 }};
        const tbody = document.querySelector('#feeders-table tbody');
        const tpl = document.getElementById('feeder-row-template').innerHTML;
        document.getElementById('add-feeder').addEventListener('click', function () {
            tbody.insertAdjacentHTML('beforeend', tpl.replaceAll('__INDEX__', index++));
        });
        tbody.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-feeder')) {
                e.target.closest('tr').remove();
            }
        });
    })();
</script>
@endpush
