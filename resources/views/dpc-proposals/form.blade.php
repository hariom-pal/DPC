@extends('layouts.app')

@section('title', $proposal->exists ? 'Edit DPC Proposal' : 'New DPC Proposal')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            @include('partials.alerts')
            <h1 class="h4 mb-1">{{ $proposal->exists ? 'Edit DPC Proposal' : 'New DPC Proposal' }}</h1>
            <p class="text-muted small mb-3">Post Selection / पद नाम जिस हेतु पदोन्नति प्रस्ताव प्रस्तुत किया जाना है</p>

            <form method="POST" action="{{ $proposal->exists ? route('dpc-proposals.update', $proposal) : route('dpc-proposals.store') }}">
                @csrf
                @if ($proposal->exists) @method('PUT') @endif

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label d-block">Type of DPC / DPC का प्रकार</label>
                            @foreach ($dpcTypes as $type)
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input @error('dpc_type_id') is-invalid @enderror" type="radio" name="dpc_type_id"
                                           id="dpc_type_{{ $type->id }}" value="{{ $type->id }}"
                                           {{ (int) old('dpc_type_id', $proposal->dpc_type_id) === $type->id ? 'checked' : '' }}
                                           {{ $type->code !== 'fresh' ? 'disabled' : '' }}>
                                    <label class="form-check-label" for="dpc_type_{{ $type->id }}">
                                        {{ $type->name_en }}
                                        @if ($type->code !== 'fresh')<span class="badge text-bg-light">coming soon</span>@endif
                                    </label>
                                </div>
                            @endforeach
                            @error('dpc_type_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="post_to_cadre_id">Post To (पद नाम जिस हेतु पदोन्नति प्रस्ताव प्रस्तुत किया जाना है)</label>
                                <select class="form-select @error('post_to_cadre_id') is-invalid @enderror" id="post_to_cadre_id" name="post_to_cadre_id" required>
                                    <option value="">— Select —</option>
                                    @foreach ($cadres as $cadre)
                                        <option value="{{ $cadre->id }}" {{ (int) old('post_to_cadre_id', $proposal->post_to_cadre_id) === $cadre->id ? 'selected' : '' }}>
                                            {{ $cadre->name }} (Class {{ $cadre->class_level->value }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('post_to_cadre_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="post_from_cadre_id">Post From (पद नाम जिससे पदोन्नति हेतु प्रस्ताव प्रस्तुत किया जाना है)</label>
                                <select class="form-select @error('post_from_cadre_id') is-invalid @enderror" id="post_from_cadre_id" name="post_from_cadre_id" required>
                                    <option value="">— Select —</option>
                                    @foreach ($cadres as $cadre)
                                        <option value="{{ $cadre->id }}" {{ (int) old('post_from_cadre_id', $proposal->post_from_cadre_id) === $cadre->id ? 'selected' : '' }}>
                                            {{ $cadre->name }} (Class {{ $cadre->class_level->value }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('post_from_cadre_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="text-muted">Class is taken from the selected post — it comes from the approved Pre-DPC / Cadre master, not re-entered here.</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="proposal_date">Proposal Date / पदोन्नति प्रस्ताव तिथि</label>
                                <input type="date" class="form-control @error('proposal_date') is-invalid @enderror" id="proposal_date" name="proposal_date"
                                       value="{{ old('proposal_date', $proposal->proposal_date?->format('Y-m-d')) }}" required>
                                @error('proposal_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label d-block">Is any legal stay in effect on this proposal? / क्या प्रस्ताव सम्बन्धी किसी प्रकार का विधिक स्थगन अथवा रोक प्रभावशील है</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="legal_stay" id="legal_stay_no" value="0" {{ ! old('legal_stay', $proposal->legal_stay) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="legal_stay_no">No / नहीं</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="legal_stay" id="legal_stay_yes" value="1" {{ old('legal_stay', $proposal->legal_stay) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="legal_stay_yes">Yes / हाँ</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success">Save Draft</button>
                <a href="{{ route('dpc-proposals.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
@endsection
