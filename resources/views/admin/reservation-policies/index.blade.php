@extends('layouts.admin')

@section('title', __('admin.reservation_policies.index.reservation_policies_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.reservation_policies.index.reservation_policies') }}</h1>
        <a href="{{ route('admin.reservation-policies.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.category') }}</th><th>{{ __('admin.reservation_policies.index.percentage') }}</th><th>{{ __('admin.reservation_policies.form.rounding') }}</th><th>{{ __('ui.effective_from') }}</th><th>{{ __('ui.effective_to') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($policies as $policy)
                        <tr>
                            <td>{{ $policy->reservationCategory?->code }} — {{ $policy->reservationCategory?->name_en }}</td>
                            <td>{{ rtrim(rtrim((string) $policy->percentage, '0'), '.') }}%</td>
                            <td class="small">{{ __('admin.reservation_policies.roundings.'.($policy->rounding ?? 'floor')) }}
                                @if ($policy->path)<div><a href="{{ route('admin.reservation-policies.document', $policy) }}">{{ __('admin.reservation_policies.index.pdf') }}</a></div>@endif
                            </td>
                            <td>{{ $policy->effective_from?->format('d-m-Y') }}</td>
                            <td>{{ $policy->effective_to?->format('d-m-Y') ?? '—' }}</td>
                            <td><x-status-badge :status="$policy->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.reservation-policies.edit', $policy) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.reservation-policies.destroy', $policy) }}" class="d-inline"
                                      data-confirm="{{ __('admin.reservation_policies.index.delete_this_policy') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('admin.reservation_policies.index.no_policies_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $policies->links() }}</div>
@endsection
