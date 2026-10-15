@extends('layouts.app')

@section('title', __('dpc.reviews.create.raise_a_review_dpc'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('dpc.reviews.create.review_dpc_proposal') }}</h1>
            <p class="text-muted small mb-3">
{!! __('dpc.reviews.create.lead') !!}
            </p>

            @error('review')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($originals->isEmpty())
                <div class="alert alert-secondary">
                    {{ __('dpc.reviews.create.none_available') }}
                </div>
            @else
                <form method="POST" action="{{ route('dpc-proposals.reviews.store') }}">
                    @csrf

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-7">
                                    <label class="form-label">{{ __('dpc.reviews.create.dpc_being_reviewed') }}</label>
                                    <select name="original_dpc_proposal_uuid" class="form-select">
                                        <option value="">{{ __('dpc.reviews.create.select_a_dpc') }}</option>
                                        @foreach ($originals as $original)
                                            <option value="{{ $original->uuid }}"
                                                @selected(old('original_dpc_proposal_uuid') === $original->uuid)>
                                                {{ $original->dpc_code }} —
                                                {{ $original->promotionFromPost?->name }} →
                                                {{ $original->promotionToPost?->name }}
                                                ({{ $original->proposal_date->format('d/m/Y') }})
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('original_dpc_proposal_uuid')
                                        <div class="text-danger small">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-5">
                                    <label class="form-label">{{ __('dpc.reviews.create.review_proposal_date') }}</label>
                                    <input type="date" name="proposal_date" class="form-control"
                                           value="{{ old('proposal_date', now()->toDateString()) }}">
                                    @error('proposal_date')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-1">{{ __('dpc.reviews.create.reason_for_review_dpc') }}</h2>
                            <p class="text-muted small mb-3">
                                {{ __('dpc.reviews.create.select_every_reason_that_applies_more_than_one_o') }}
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
                                               placeholder="{{ __('dpc.reviews.create.which_officer_or_entry_optional') }}"
                                               value="{{ old('details.'.$reason->id) }}">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <button class="btn btn-primary">{{ __('dpc.reviews.create.raise_review_dpc') }}</button>
                    <a class="btn btn-link" href="{{ route('dpc-proposals.index') }}">{{ __('ui.cancel') }}</a>
                </form>
            @endif
        </div>
    </div>
@endsection
