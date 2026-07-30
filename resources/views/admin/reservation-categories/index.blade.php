@extends('layouts.admin')

@section('title', 'Reservation Categories')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Reservation Categories / आरक्षण वर्ग</h1>
        <a href="{{ route('admin.reservation-categories.create') }}" class="btn btn-success btn-sm">+ New</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Code</th><th>Name (EN)</th><th>नाम (HI)</th><th>Reserved?</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr>
                            <td>{{ $category->code }}</td>
                            <td>{{ $category->name_en }}</td>
                            <td>{{ $category->name_hi }}</td>
                            <td>{!! $category->is_reserved ? '<span class="badge text-bg-info">Reserved</span>' : '<span class="badge text-bg-light">Unreserved</span>' !!}</td>
                            <td><x-status-badge :status="$category->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.reservation-categories.edit', $category) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.reservation-categories.destroy', $category) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this category?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $categories->links() }}</div>
@endsection
