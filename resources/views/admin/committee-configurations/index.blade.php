@extends('layouts.admin')

@section('title', __('admin.committee_rules.title'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">{{ __('admin.committee_rules.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.committee_rules.intro') }}</p>
        </div>
        <a href="{{ route('admin.committee-configurations.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    {{-- Change item 15's bounds against what is actually stored.
         Stated before the table, because an Administrator reading the rows has no
         way to know that 1–5 is a leftover rather than a decision. --}}
    @if ($drift !== [])
        <div class="alert alert-warning">
            <strong class="d-block mb-1">{{ __('admin.committee_configurations.index.drift_heading') }}</strong>
            <p class="small mb-2">{{ __('admin.committee_configurations.index.drift_explained') }}</p>
            <ul class="mb-0 small">
                @foreach ($drift as $configurationId => $difference)
                    <li>
                        {{ __('admin.committee_configurations.index.drift_row', [
                            'stored_min' => $difference['stored']['min'],
                            'stored_max' => $difference['stored']['max'],
                            'min' => $difference['configured']['min'],
                            'max' => $difference['configured']['max'],
                        ]) }}
                        <form method="POST" class="d-inline"
                              action="{{ route('admin.committee-configurations.align-bounds', $configurationId) }}"
                              data-confirm="{{ __('admin.committee_configurations.index.drift_confirm') }}">
                            @csrf
                            <button class="btn btn-warning btn-sm ms-2">{{ __('admin.committee_configurations.index.drift_apply') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('admin.committee_rules.col_members') }}</th><th>{{ __('admin.committee_rules.col_composition') }}</th><th>{{ __('admin.committee_rules.col_effective') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($configurations as $configuration)
                        <tr>
                            <td>{{ __('admin.committee_rules.members_value', ['min' => $configuration->min_members - 1, 'max' => $configuration->max_members - 1]) }}</td>
                            <td class="small">
                                {{ __('admin.committee_rules.chairman_short') }}
                                @foreach ((array) $configuration->required_categories as $value)
                                    · {{ \App\Domain\Committee\Enums\MemberCategory::tryFrom($value)?->label() ?? $value }}
                                @endforeach
                                @if ($configuration->requires_reserved_category)
                                    <div class="text-muted">{{ __('admin.committee_rules.requires_reserved_category') }}</div>
                                @endif
                            </td>
                            <td class="small">{{ $configuration->effective_from?->format('d-m-Y') ?? '—' }} → {{ $configuration->effective_to?->format('d-m-Y') ?? __('admin.committee_rules.open_ended') }}</td>
                            <td><x-status-badge :status="$configuration->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.committee-configurations.edit', $configuration) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.committee-configurations.destroy', $configuration) }}" class="d-inline"
                                      data-confirm="{{ __('admin.committee_configurations.index.delete_this_configuration') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">{{ __('admin.committee_configurations.index.no_configurations_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $configurations->links() }}</div>
@endsection
