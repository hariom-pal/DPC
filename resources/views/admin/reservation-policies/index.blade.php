@extends('layouts.admin')

@section('title', 'Reservation Policies')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Reservation Policies / आरक्षण नीति</h1>
        <a href="{{ route('admin.reservation-policies.create') }}" class="btn btn-success btn-sm">+ New</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Category</th><th>Percentage</th><th>Effective From</th><th>Effective To</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($policies as $policy)
                        <tr>
                            <td>{{ $policy->reservationCategory?->code }} — {{ $policy->reservationCategory?->name_en }}</td>
                            <td>{{ $policy->percentage }}%</td>
                            <td>{{ $policy->effective_from?->format('d-m-Y') }}</td>
                            <td>{{ $policy->effective_to?->format('d-m-Y') ?? '—' }}</td>
                            <td><x-status-badge :status="$policy->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.reservation-policies.edit', $policy) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.reservation-policies.destroy', $policy) }}" class="d-inline"
                                      data-confirm="Delete this policy?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No policies yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $policies->links() }}</div>
@endsection
