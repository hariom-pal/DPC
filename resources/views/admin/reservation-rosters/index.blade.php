@extends('layouts.admin')

@section('title', 'Reservation Rosters')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Reservation Rosters / आरक्षण रोस्टर</h1>
        <a href="{{ route('admin.reservation-rosters.create') }}" class="btn btn-success btn-sm">+ New Roster</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Name</th><th>Department</th><th>Cadre</th><th>Size</th><th>Points</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($rosters as $roster)
                        <tr>
                            <td>{{ $roster->name }}</td>
                            <td>{{ $roster->department?->name }}</td>
                            <td>{{ $roster->cadre?->name }}</td>
                            <td>{{ $roster->size }}</td>
                            <td>{{ $roster->points()->count() }} / {{ $roster->size }}</td>
                            <td><x-status-badge :status="$roster->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.reservation-rosters.edit', $roster) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.reservation-rosters.destroy', $roster) }}" class="d-inline"
                                      data-confirm="Delete this roster?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No rosters yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $rosters->links() }}</div>
@endsection
