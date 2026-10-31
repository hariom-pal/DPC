@extends('layouts.app')
@section('title', __('esign.page.title'))
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <h1 class="h4 mb-1">{{ __('esign.page.title_'.$kind) }}</h1>
            <p class="text-muted small">{{ __('esign.page.lead') }}</p>

            @include('partials.alerts')
            @include('partials.esign-issue', ['proposal' => $proposal])

            <form method="POST" action="{{ route('esign.start', ['kind' => $kind, 'uuid' => $proposal->uuid]) }}" class="card border-0 shadow-sm form-section" id="esign-consent">
                @csrf
                <div class="card-header"><strong>{{ __('esign.page.what') }}</strong></div>
                <div class="card-body">
                    <div class="summary-strip small">
                        <div class="summary-item"><span class="summary-label">{{ __('esign.page.document') }}</span><span class="summary-value">{{ $label }}</span></div>
                        <div class="summary-item"><span class="summary-label">{{ __('esign.page.signer') }}</span><span class="summary-value">{{ $signer->name }}</span></div>
                        <div class="summary-item"><span class="summary-label">{{ __('ui.status') }}</span><span class="summary-value">{{ $proposal->status->label() }}</span></div>
                    </div>
                    <p class="small">{{ __('esign.page.name_rule', ['name' => $signer->name]) }}</p>
                    @if ($simulated)
                        <div class="alert alert-warning small py-2">{{ __('esign.page.simulated') }}</div>
                    @endif
                    @if ($kind === 'pre-dpc-submit')
                        <div class="mb-3">@include('partials.declaration', ['key' => 'pre_dpc_submit', 'checked' => request()->boolean('declaration')])</div>
                    @endif
                    <div class="form-check">
                        <input class="form-check-input @error('consent') is-invalid @enderror" type="checkbox" name="consent" value="1" id="consent">
                        <label class="form-check-label small" for="consent">{{ __('esign.page.consent') }}</label>
                        @error('consent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="card-footer bg-transparent d-flex gap-2">
                    <button type="submit" class="btn btn-success">{{ __('esign.page.proceed') }}</button>
                    <a href="{{ route($proposal instanceof \App\Domain\PreDpc\Models\PreDpcProposal ? 'pre-dpc.show' : 'dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
@endsection
