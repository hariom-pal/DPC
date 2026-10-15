@extends('layouts.app')

@section('title', __('admin.integrations.index.integration_status'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('admin.integrations.index.integration_status') }}</h1>
            <p class="text-muted small mb-3">
{!! __('admin.integrations.index.lead') !!}
            </p>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('admin.integrations.index.feature_flags') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>{{ __('admin.integrations.index.integration') }}</th><th>{{ __('admin.integrations.index.environment_variable') }}</th><th>{{ __('admin.integrations.index.state') }}</th><th>{{ __('admin.integrations.index.behaviour_while_disabled') }}</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($features as $feature)
                                    <tr>
                                        <td>
                                            <strong>{{ $feature->label() }}</strong>
                                            @if ($feature->awaitingExternalApi())
                                                <br><span class="badge text-bg-light">{{ __('admin.integrations.index.awaiting_external_api') }}</span>
                                            @endif
                                        </td>
                                        <td><code>{{ $feature->envKey() }}</code></td>
                                        <td>
                                            @if ($feature->enabled())
                                                <span class="badge text-bg-success">{{ __('admin.integrations.index.enabled') }}</span>
                                            @else
                                                <span class="badge text-bg-secondary">{{ __('admin.integrations.index.disabled') }}</span>
                                            @endif
                                        </td>
                                        <td class="small text-muted">{{ $feature->fallbackBehaviour() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('admin.integrations.index.resolved_providers') }}</h2>
                    <p class="text-muted small">
                        {{ __('admin.integrations.index.which_implementation_each_contract_is_actually_b') }}
                    </p>
                    <dl class="row mb-0">
                        @foreach ($boundProviders as $contract => $provider)
                            <dt class="col-sm-4">{{ $contract }}</dt>
                            <dd class="col-sm-8">
                                <code>{{ $provider }}</code>
                                @if (str_starts_with($provider, 'UNAVAILABLE'))
                                    <span class="badge text-bg-danger">{{ __('admin.integrations.index.check_configuration') }}</span>
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </div>
            </div>

            <div class="alert alert-info small mb-0">
                {!! __('admin.integrations.index.digital_signing_note') !!}
            </div>
        </div>
    </div>
@endsection
