@extends('layouts.app')

@section('title', 'Integration Status')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Integration Status</h1>
            <p class="text-muted small mb-3">
                Every external integration is switched by an environment variable and defaults to <strong>off</strong>.
                The application runs and is fully demonstrable with all of them disabled — each has a defined
                fallback below, so "disabled" never means "broken".
                These values are read from the running application's configuration, not from a file on disk.
            </p>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">Feature Flags</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr><th>Integration</th><th>Environment Variable</th><th>State</th><th>Behaviour while disabled</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($features as $feature)
                                    <tr>
                                        <td>
                                            <strong>{{ $feature->label() }}</strong>
                                            @if ($feature->awaitingExternalApi())
                                                <br><span class="badge text-bg-light">awaiting external API</span>
                                            @endif
                                        </td>
                                        <td><code>{{ $feature->envKey() }}</code></td>
                                        <td>
                                            @if ($feature->enabled())
                                                <span class="badge text-bg-success">Enabled</span>
                                            @else
                                                <span class="badge text-bg-secondary">Disabled</span>
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
                    <h2 class="h6">Resolved Providers</h2>
                    <p class="text-muted small">
                        Which implementation each contract is actually bound to in this environment — the check that
                        confirms production is not signing through the mock provider.
                    </p>
                    <dl class="row mb-0">
                        @foreach ($boundProviders as $contract => $provider)
                            <dt class="col-sm-4">{{ $contract }}</dt>
                            <dd class="col-sm-8">
                                <code>{{ $provider }}</code>
                                @if (str_starts_with($provider, 'UNAVAILABLE'))
                                    <span class="badge text-bg-danger">check configuration</span>
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </div>
            </div>

            <div class="alert alert-info small mb-0">
                <strong>Digital signing in production.</strong> While <code>FEATURE_CSC_DIGISIGN</code> is disabled,
                a production environment <strong>refuses</strong> to approve a proposal rather than signing it with the
                mock provider, and reports a business error to the officer. Non-production environments sign through the
                mock so the workflow stays demonstrable.
            </div>
        </div>
    </div>
@endsection
