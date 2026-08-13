@extends('layouts.app')

@section('title', 'Import Preview — ' . $batch->template->label())

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-1">
                <h1 class="h4 mb-0">Import Preview</h1>
                <span class="badge {{ $batch->status->badgeClass() }} fs-6">{{ $batch->status->label() }}</span>
            </div>
            <p class="text-muted small mb-3">
                {{ $batch->template->label() }} · <strong>{{ $batch->original_filename }}</strong>
                · uploaded by {{ $batch->uploader?->name ?? '—' }} on {{ $batch->created_at?->format('d-m-Y H:i') }}
                @if ($batch->file_hash)
                    <br><span class="font-monospace" style="font-size:.75rem">SHA-256 {{ $batch->file_hash }}</span>
                    @if ($fileIntact)<span class="badge text-bg-light">integrity verified</span>@endif
                @endif
            </p>

            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">Total Rows</div>
                        <div class="h4 mb-0">{{ $batch->total_rows }}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">Valid</div>
                        <div class="h4 mb-0 text-success">{{ $batch->total_rows - $batch->rejected_count }}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">Rejected</div>
                        <div class="h4 mb-0 {{ $batch->rejected_count > 0 ? 'text-danger' : '' }}">{{ $batch->rejected_count }}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">Imported</div>
                        <div class="h4 mb-0">{{ $batch->imported_count }}</div>
                    </div></div>
                </div>
            </div>

            @error('commit')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($duplicates->isNotEmpty())
                <div class="alert alert-warning py-2 small">
                    <strong>This exact file has been uploaded before.</strong>
                    Identical content (same SHA-256) was uploaded for this section as
                    @foreach ($duplicates as $duplicate)
                        batch #{{ $duplicate->id }} by {{ $duplicate->uploader?->name ?? 'unknown' }}
                        on {{ $duplicate->created_at?->format('d-m-Y H:i') }} ({{ $duplicate->status->label() }}){{ ! $loop->last ? ',' : '' }}
                    @endforeach.
                    Continue only if re-importing is what you intend.
                </div>
            @endif

            @unless ($fileIntact)
                <div class="alert alert-danger py-2 small">
                    <strong>File integrity check failed.</strong> The stored copy of this upload no longer matches the
                    SHA-256 recorded when it was received. Treat this batch as unreliable evidence and re-upload the file.
                </div>
            @endunless

            @if ($batch->status->isCommittable())
                <div class="alert alert-warning py-2 small">
                    <strong>Nothing has been saved yet.</strong> Committing this import <strong>replaces</strong> the
                    existing {{ $batch->template->label() }} section on this proposal with the {{ $batch->total_rows - $batch->rejected_count }}
                    valid row(s) below — it does not add to what is already there.
                    @if ($batch->rejected_count > 0)
                        The {{ $batch->rejected_count }} rejected row(s) will <strong>not</strong> be imported; download the
                        error report, fix the source file, and upload again if you need them.
                    @endif
                </div>
            @endif

            <div class="d-flex flex-wrap gap-2 mb-3">
                @if ($batch->status->isCommittable() && $batch->rejected_count < $batch->total_rows)
                    <form method="POST" action="{{ route('dpc-proposals.imports.commit', [$proposal, $batch]) }}"
                          onsubmit="return confirm('Replace the {{ $batch->template->label() }} section with the valid rows from this file?');">
                        @csrf<button class="btn btn-success btn-sm">Commit {{ $batch->total_rows - $batch->rejected_count }} Row(s)</button>
                    </form>
                @endif

                @if ($batch->rejected_count > 0)
                    <a href="{{ route('dpc-proposals.imports.errors', [$proposal, $batch]) }}" class="btn btn-outline-danger btn-sm">Download Error Report (CSV)</a>
                @endif

                @if (! $batch->status->isTerminal())
                    <form method="POST" action="{{ route('dpc-proposals.imports.cancel', [$proposal, $batch]) }}">
                        @csrf<button class="btn btn-outline-secondary btn-sm">Cancel Import</button>
                    </form>
                @endif

                <a href="{{ route($batch->template->sectionRoute(), $proposal) }}" class="btn btn-link btn-sm">← Back to section</a>
            </div>

            @if ($batch->rejected_count > 0)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6 text-danger">Rejected Rows ({{ $batch->rejected_count }})</h2>
                        <p class="text-muted small">Row numbers refer to lines in your uploaded file.</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th style="width:8rem">File Row</th><th style="width:14rem">Name</th><th>Errors</th></tr></thead>
                                <tbody>
                                    @foreach ($rejectedRows as $row)
                                        <tr>
                                            <td>{{ $row->row_number }}</td>
                                            <td>{{ $row->payload['name'] ?? '—' }}</td>
                                            <td>
                                                <ul class="mb-0 small">
                                                    @foreach ($row->errorMessages() as $message)<li>{{ $message }}</li>@endforeach
                                                </ul>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $rejectedRows->links() }}
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-success">Valid Rows ({{ $batch->total_rows - $batch->rejected_count }})</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th style="width:8rem">File Row</th><th>Name</th><th>DOB</th><th>Category</th><th>Details</th></tr></thead>
                            <tbody>
                                @forelse ($validRows as $row)
                                    <tr>
                                        <td>{{ $row->row_number }}</td>
                                        <td>{{ $row->payload['name'] ?? '—' }}</td>
                                        <td>{{ $row->payload['dob'] ?? '—' }}</td>
                                        <td>{{ $categoryCodes[$row->payload['reservation_category_id'] ?? null] ?? '—' }}</td>
                                        <td class="small text-muted">
                                            @if (! empty($row->payload['cr_grades']))
                                                CR years: {{ collect($row->payload['cr_grades'])->pluck('year')->implode(', ') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-3">No valid rows in this file.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $validRows->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
