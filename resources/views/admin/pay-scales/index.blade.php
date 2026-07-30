@extends('layouts.admin')

@section('title', 'Pay Scales')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Pay Scales / वेतनमान</h1>
        <a href="{{ route('admin.pay-scales.create') }}" class="btn btn-success btn-sm">+ New Pay Scale</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>Label</th><th>Code</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($payScales as $payScale)
                        <tr>
                            <td>{{ $payScale->label }}</td>
                            <td>{{ $payScale->code ?? '—' }}</td>
                            <td><x-status-badge :status="$payScale->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.pay-scales.edit', $payScale) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.pay-scales.destroy', $payScale) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this pay scale?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No pay scales yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $payScales->links() }}</div>
@endsection
