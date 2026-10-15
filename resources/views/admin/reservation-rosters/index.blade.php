@extends('layouts.admin')

@section('title', __('admin.reservation_rosters.index.reservation_rosters_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.reservation_rosters.index.reservation_rosters') }}</h1>
        <a href="{{ route('admin.reservation-rosters.create') }}" class="btn btn-success btn-sm">{{ __('admin.reservation_rosters.index.new_roster') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.name') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.post') }}</th><th>{{ __('admin.reservation_rosters.index.size') }}</th><th>{{ __('admin.reservation_rosters.index.points') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($rosters as $roster)
                        <tr>
                            <td>{{ $roster->name }}</td>
                            <td>{{ $roster->department?->name }}</td>
                            <td>{{ $roster->post?->name }}</td>
                            <td>{{ $roster->size }}</td>
                            <td>{{ $roster->points()->count() }} / {{ $roster->size }}</td>
                            <td><x-status-badge :status="$roster->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.reservation-rosters.edit', $roster) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.reservation-rosters.destroy', $roster) }}" class="d-inline"
                                      data-confirm="{{ __('admin.reservation_rosters.index.delete_this_roster') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('admin.reservation_rosters.index.no_rosters_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $rosters->links() }}</div>
@endsection
