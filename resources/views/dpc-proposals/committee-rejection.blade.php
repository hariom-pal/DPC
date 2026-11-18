@extends('layouts.app')

@section('title', __('dpc.committee_rejection.mark'))

@section('content')
    {{-- The Secretary marks, candidate by candidate, the sections a DPC rejected by its
         DPC Chairman is wrong in, each with a remark, and sends it to the AH (2026-11-18). --}}
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')
            <h1 class="h4 mb-1">{{ __('dpc.committee_rejection.mark') }}</h1>
            <p class="text-muted small mb-2">{{ $proposal->citation() }}</p>
            <div class="alert alert-warning small" id="committee-rejection-remark">
                {{ __('dpc.committee_rejection.banner', ['member' => $proposal->committee_return['member'] ?? '—']) }}
                <div>{{ __('dpc.committee_rejection.remark') }}: {{ $proposal->committee_return['remarks'] ?? '—' }}</div>
            </div>
            <p class="small text-muted">{{ __('dpc.committee_rejection.help') }}</p>
            @error('candidates')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <form method="POST" action="{{ route('dpc-proposals.committee-rejection.mark', $proposal) }}" id="committee-rejection-form">
                @csrf
                @foreach ($rows as $row)
                    <div class="card border-0 shadow-sm mb-2" data-rejection-candidate="{{ $row->id }}">
                        <div class="card-body py-2">
                            <div class="fw-semibold small mb-1">{{ $row->sn }}. {{ $row->name }} <span class="text-muted">{{ $row->employee_number }}</span></div>
                            <div class="row g-2">
                                @foreach ($sections as $section)
                                    <div class="col-md-3">
                                        <label class="form-label small mb-0" for="c-{{ $row->id }}-{{ $section }}">{{ __('dpc.committee_decision.sections.'.$section) }}</label>
                                        <input type="text" maxlength="2000" class="form-control form-control-sm" id="c-{{ $row->id }}-{{ $section }}"
                                               name="candidates[{{ $row->id }}][{{ $section }}]" value="{{ old('candidates.'.$row->id.'.'.$section) }}"
                                               placeholder="{{ __('dpc.committee_rejection.placeholder') }}">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
                <button class="btn btn-primary mt-2" id="send-to-ah">{{ __('dpc.committee_rejection.send') }}</button>
                <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary mt-2">{{ __('ui.cancel') }}</a>
            </form>
        </div>
    </div>
@endsection
