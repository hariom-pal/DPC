@extends('layouts.app')

@section('title', 'Raise a Review DPC')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Review DPC — Proposal</h1>
            <p class="text-muted small mb-3">
                A Review corrects an error found in a DPC that has already been conducted and approved. The
                original DPC is <strong>never changed</strong> — this creates a new proposal, pre-filled with
                the original's details, which you then correct.
            </p>

            @error('review')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($originals->isEmpty())
                <div class="alert alert-secondary">
                    There is no DPC available to review. A Review can be raised only against a DPC whose
                    result the Commission has approved, and only against the most recent DPC in a correction
                    chain.
                </div>
            @else
                <form method="POST" action="{{ route('dpc-proposals.reviews.store') }}">
                    @csrf

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label">DPC being reviewed</label>
                                    <select name="original_dpc_proposal_uuid" class="form-select">
                                        <option value="">Select a DPC…</option>
                                        @foreach ($originals as $original)
                                            <option value="{{ $original->uuid }}"
                                                @selected(old('original_dpc_proposal_uuid') === $original->uuid)>
                                                {{ $original->dpc_code }} —
                                                {{ $original->postFromCadre?->name }} →
                                                {{ $original->postToCadre?->name }}
                                                ({{ $original->proposal_date->format('d/m/Y') }})
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('original_dpc_proposal_uuid')
                                        <div class="text-danger small">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-5">
                                    <label class="form-label">Review proposal date</label>
                                    <input type="date" name="proposal_date" class="form-control"
                                           value="{{ old('proposal_date', now()->toDateString()) }}">
                                    @error('proposal_date')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-1">Reason for Review DPC</h2>
                            <p class="text-muted small mb-3">
                                Select every reason that applies. More than one often does — a name left off the
                                seniority list is frequently also a roster-point error.
                            </p>

                            @error('reasons')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                            @foreach ($reasons as $reason)
                                <div class="row g-2 align-items-center mb-2">
                                    <div class="col-md-5">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="reasons[]"
                                                   value="{{ $reason->id }}" id="reason-{{ $reason->id }}"
                                                   @checked(in_array((string) $reason->id, (array) old('reasons', []), true))>
                                            {{-- `name_en` is the column; `name` does not exist on a
                                                 master, so the label silently fell back to the raw
                                                 code (`name_omitted`) rather than naming the ground. --}}
                                            <label class="form-check-label" for="reason-{{ $reason->id }}">
                                                {{ $reason->name_en ?: $reason->code }}
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <input type="text" name="details[{{ $reason->id }}]"
                                               class="form-control form-control-sm"
                                               placeholder="Which officer or entry? (optional)"
                                               value="{{ old('details.'.$reason->id) }}">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <button class="btn btn-primary">Raise Review DPC</button>
                    <a class="btn btn-link" href="{{ route('dpc-proposals.index') }}">Cancel</a>
                </form>
            @endif
        </div>
    </div>
@endsection
