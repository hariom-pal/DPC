@extends('layouts.admin')

@section('title', __($configuration->exists ? 'admin.committee_configurations.form.title_edit' : 'admin.committee_configurations.form.title_new'))

@php($c = $configuration)

@section('content')
    <h1 class="h4 mb-3">{{ $c->exists ? 'Edit Committee Configuration' : 'New Committee Configuration' }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $c->exists ? route('admin.committee-configurations.update', $c) : route('admin.committee-configurations.store') }}">
                @csrf
                @if ($c->exists) @method('PUT') @endif

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="committee_type_id">{{ __('ui.committee_type') }}</label>
                        <select class="form-select @error('committee_type_id') is-invalid @enderror" id="committee_type_id" name="committee_type_id" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($committeeTypes as $type)
                                <option value="{{ $type->id }}" @selected(old('committee_type_id', $c->committee_type_id) == $type->id)>{{ $type->code }} — {{ $type->name_en }}</option>
                            @endforeach
                        </select>
                        @error('committee_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="status">{{ __('ui.status') }}</label>
                        <select class="form-select" id="status" name="status" required>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(old('status', $c->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="min_members">{{ __('admin.committee_configurations.form.minimum_members') }}</label>
                        <input type="number" min="1" class="form-control @error('min_members') is-invalid @enderror" id="min_members" name="min_members" value="{{ old('min_members', $c->min_members) }}" required>
                        @error('min_members')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="max_members">{{ __('admin.committee_configurations.form.maximum_members') }}</label>
                        <input type="number" min="1" class="form-control @error('max_members') is-invalid @enderror" id="max_members" name="max_members" value="{{ old('max_members', $c->max_members) }}" required>
                        @error('max_members')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="quorum">{{ __('ui.quorum') }}</label>
                        <input type="number" min="1" class="form-control @error('quorum') is-invalid @enderror" id="quorum" name="quorum" value="{{ old('quorum', $c->quorum) }}" required>
                        @error('quorum')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="voting_method">{{ __('admin.committee_configurations.form.voting_method') }}</label>
                        <input type="text" list="voting_methods" class="form-control @error('voting_method') is-invalid @enderror" id="voting_method" name="voting_method" value="{{ old('voting_method', $c->voting_method) }}" required>
                        <datalist id="voting_methods"><option value="Open"><option value="Secret"><option value="None"></datalist>
                        @error('voting_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="majority_type">{{ __('admin.committee_configurations.form.majority_type') }}</label>
                        <input type="text" list="majority_types" class="form-control @error('majority_type') is-invalid @enderror" id="majority_type" name="majority_type" value="{{ old('majority_type', $c->majority_type) }}" required>
                        <datalist id="majority_types"><option value="Simple"><option value="Two-Thirds"><option value="Consensus"></datalist>
                        @error('majority_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="tie_break_rule">{{ __('admin.committee_configurations.form.tie_break_rule') }}</label>
                        <input type="text" list="tie_breaks" class="form-control @error('tie_break_rule') is-invalid @enderror" id="tie_break_rule" name="tie_break_rule" value="{{ old('tie_break_rule', $c->tie_break_rule) }}" required>
                        <datalist id="tie_breaks"><option value="Chairman casting vote"><option value="Senior-most member"></datalist>
                        @error('tie_break_rule')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="row">
                    @php($flags = [
                        'chairman_mandatory' => 'Chairman Mandatory',
                        'digital_signature_required' => 'Digital Signature Required',
                        'remarks_required' => 'Remarks Required',
                        'external_member_allowed' => 'External Member Allowed',
                        'secretary_required' => 'Secretary Required',
                    ])
                    @foreach ($flags as $field => $label)
                        <div class="col-md-4 mb-2">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="{{ $field }}" name="{{ $field }}" value="1"
                                    @checked(old($field, $c->{$field} ?? ($field === 'chairman_mandatory')))>
                                <label class="form-check-label" for="{{ $field }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="row mt-2">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_from">{{ __('ui.effective_from') }}</label>
                        <input type="date" class="form-control" id="effective_from" name="effective_from" value="{{ old('effective_from', $c->effective_from?->format('Y-m-d')) }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="effective_to">{{ __('ui.effective_to') }}</label>
                        <input type="date" class="form-control @error('effective_to') is-invalid @enderror" id="effective_to" name="effective_to" value="{{ old('effective_to', $c->effective_to?->format('Y-m-d')) }}">
                        @error('effective_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-success">{{ __('ui.save') }}</button>
                <a href="{{ route('admin.committee-configurations.index') }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
