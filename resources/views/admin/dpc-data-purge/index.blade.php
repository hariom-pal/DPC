@extends('layouts.admin')

@section('title', __('admin.dpc_data_purge.title'))

@section('content')
    <h1 class="h4 mb-1">{{ __('admin.dpc_data_purge.title') }}</h1>
    <p class="text-muted small mb-1">{{ __('admin.dpc_data_purge.lead') }}</p>
    <p class="text-muted small mb-3">{{ __('admin.dpc_data_purge.kept') }}</p>

    @include('partials.alerts', ['inlineErrors' => true])

    <dl class="row small mb-3">
        <dt class="col-sm-3 col-lg-2">{{ __('admin.dpc_data_purge.environment') }}</dt>
        <dd class="col-sm-9 col-lg-10"><code>{{ $plan['environment'] }}</code></dd>
        <dt class="col-sm-3 col-lg-2">{{ __('admin.dpc_data_purge.database') }}</dt>
        <dd class="col-sm-9 col-lg-10"><code>{{ $plan['database'] }}</code></dd>
    </dl>

    @unless ($plan['allowed'])
        <div class="alert alert-secondary" id="dpc-data-purge-not-allowed">{{ __('admin.dpc_data_purge.not_allowed', ['environment' => $plan['environment']]) }}</div>
    @endunless

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('admin.dpc_data_purge.table') }}</th><th>{{ __('admin.dpc_data_purge.holds') }}</th><th class="text-end">{{ __('admin.dpc_data_purge.rows') }}</th></tr></thead>
                        <tbody>
                            @foreach ($plan['tables'] as $row)
                                <tr class="{{ $row['rows'] === 0 ? 'text-muted' : '' }}">
                                    <td><code>{{ $row['table'] }}</code></td>
                                    <td class="small">{{ $row['what'] }}</td>
                                    <td class="text-end">{{ number_format($row['rows']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>{{ __('admin.dpc_data_purge.shared_title') }}</strong></div>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        @foreach ($plan['shared'] as $row)
                            <tr class="{{ $row['rows'] === 0 ? 'text-muted' : '' }}">
                                <td><code>{{ $row['table'] }}</code></td>
                                <td class="text-end">{{ number_format($row['rows']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @foreach ($plan['set_null'] as $effect)
        <div class="alert alert-warning small py-2">{{ __('admin.dpc_data_purge.set_null', $effect) }}</div>
    @endforeach

    @if ($plan['blockers'] !== [])
        <div class="alert alert-danger">
            <strong>{{ __('admin.dpc_data_purge.blocked') }}</strong>
            <ul class="mb-0 small">
                @foreach ($plan['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($plan['allowed'] && $plan['blockers'] === [])
        <div class="card border-danger shadow-sm">
            <div class="card-body">
                <p class="fw-semibold mb-1">{{ __('admin.dpc_data_purge.summary', ['rows' => number_format($plan['total']), 'files' => number_format($plan['files'])]) }}</p>
                <p class="text-danger small mb-3">{{ __('admin.dpc_data_purge.backup') }}</p>

                <form method="POST" action="{{ route('admin.dpc-data-purge.destroy') }}" class="row g-2 align-items-end" id="dpc-data-purge-form">
                    @csrf
                    @method('DELETE')
                    {{-- Users too, Administrators kept and active (2026-11-03). --}}
                    <div class="col-12">
                        <div class="form-check" id="dpc-data-purge-users">
                            <input class="form-check-input" type="checkbox" id="with_users" name="with_users" value="1" @checked(old('with_users')) @disabled($users['blockers'] !== [])>
                            <label class="form-check-label fw-semibold text-danger" for="with_users">
                                {{ __('admin.dpc_data_purge.with_users', ['count' => number_format($users['delete'])]) }}
                            </label>
                            <div class="form-text">
                                {{ __('admin.dpc_data_purge.with_users_hint', ['kept' => collect($users['kept'])->map(fn ($u) => $u['name'])->implode(', ') ?: '—']) }}
                            </div>
                            @foreach ($users['blockers'] as $blocker)
                                <div class="small text-danger">{{ $blocker }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="confirmation">{{ __('admin.dpc_data_purge.confirm_label', ['database' => $plan['database']]) }}</label>
                        <input type="text" class="form-control @error('confirmation') is-invalid @enderror" id="confirmation" name="confirmation" autocomplete="off" required>
                        @error('confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-danger">{{ __('admin.dpc_data_purge.submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
