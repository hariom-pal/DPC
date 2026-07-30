<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ $title }}</h1>
    <a href="{{ route($routeBase.'.create') }}" class="btn btn-success btn-sm">+ New</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr><th>Code</th><th>Name (EN)</th><th>नाम (HI)</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>{{ $item->code }}</td>
                        <td>{{ $item->name_en }}</td>
                        <td>{{ $item->name_hi }}</td>
                        <td><x-status-badge :status="$item->status" /></td>
                        <td class="text-end">
                            <a href="{{ route($routeBase.'.edit', $item) }}" class="btn btn-outline-primary btn-sm">Edit</a>
                            <form method="POST" action="{{ route($routeBase.'.destroy', $item) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this record?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No records yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $items->links() }}</div>
