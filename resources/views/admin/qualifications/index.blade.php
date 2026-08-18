@extends('layouts.admin')
@section('title', 'Qualifications')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Qualification Master / अर्हता मास्टर</h1>
        <a href="{{ route('admin.qualifications.create') }}" class="btn btn-success btn-sm">+ New</a>
    </div>

    <p class="text-body-secondary small">
        The list a recruitment rule's <strong>Minimum Qualification</strong> is chosen from. A qualification that
        rules already cite cannot be deleted — set it to <em>Inactive</em> to take it out of the dropdown while
        leaving those rules untouched.
    </p>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name (EN)</th>
                        <th>नाम (HI)</th>
                        <th>Description</th>
                        <th class="text-end">Used by</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($qualifications as $qualification)
                        <tr>
                            <td><code>{{ $qualification->code }}</code></td>
                            <td>{{ $qualification->name_en }}</td>
                            <td>{{ $qualification->name_hi }}</td>
                            <td class="small text-body-secondary">{{ $qualification->description }}</td>
                            <td class="text-end">
                                @if ($qualification->recruitment_rule_feeders_count > 0)
                                    <span class="badge text-bg-light">{{ $qualification->recruitment_rule_feeders_count }} rule(s)</span>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                            <td><x-status-badge :status="$qualification->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.qualifications.edit', $qualification) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                                <form method="POST" action="{{ route('admin.qualifications.destroy', $qualification) }}" class="d-inline"
                                      onsubmit="return confirm('Delete this qualification?');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm"
                                        @disabled($qualification->recruitment_rule_feeders_count > 0)>Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $qualifications->links() }}</div>
@endsection
