@extends('layouts.app')

@section('title', __('ui.screen.import_preview').' — '.$batch->template->label())

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <div class="d-flex justify-content-between align-items-center mb-1">
                <h1 class="h4 mb-0">{{ __('dpc.imports.preview.import_preview') }}</h1>
                <span class="badge {{ $batch->status->badgeClass() }} fs-6">{{ $batch->status->label() }}</span>
            </div>
            <p class="text-muted small mb-3">
                {{ $batch->template->label() }} · <strong>{{ $batch->original_filename }}</strong>
{{ __('dpc.imports.preview.uploaded_by', ['actor' => $batch->uploader?->name ?? '—', 'at' => $batch->created_at?->format('d-m-Y H:i')]) }}
                @if ($batch->file_hash)
                    <br><span class="font-monospace" style="font-size:.75rem">SHA-256 {{ $batch->file_hash }}</span>
                    @if ($fileIntact)<span class="badge text-bg-light">{{ __('dpc.imports.preview.integrity_verified') }}</span>@endif
                @endif
            </p>

            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">{{ __('dpc.imports.preview.total_rows') }}</div>
                        <div class="h4 mb-0">{{ $batch->total_rows }}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">{{ __('dpc.imports.preview.valid') }}</div>
                        <div class="h4 mb-0 text-success">{{ $batch->total_rows - $batch->rejected_count }}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">{{ __('dpc.imports.preview.rejected') }}</div>
                        <div class="h4 mb-0 {{ $batch->rejected_count > 0 ? 'text-danger' : '' }}">{{ $batch->rejected_count }}</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                        <div class="text-muted small">{{ __('dpc.imports.preview.imported') }}</div>
                        <div class="h4 mb-0">{{ $batch->imported_count }}</div>
                    </div></div>
                </div>
            </div>

            @error('commit')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($duplicates->isNotEmpty())
                <div class="alert alert-warning py-2 small">
{!! __('dpc.imports.preview.duplicate_lead') !!}
                    @foreach ($duplicates as $duplicate)
{{ __('dpc.imports.preview.duplicate_batch', [
                            'id' => $duplicate->id,
                            'actor' => $duplicate->uploader?->name ?? __('ui.unknown'),
                            'at' => $duplicate->created_at?->format('d-m-Y H:i'),
                            'status' => $duplicate->status->label(),
                        ]) }}{{ ! $loop->last ? ',' : '' }}
                    @endforeach.
{{ __('dpc.imports.preview.duplicate_continue') }}
                </div>
            @endif

            @unless ($fileIntact)
                <div class="alert alert-danger py-2 small">
                    {!! __('dpc.imports.preview.integrity_failed') !!}
                </div>
            @endunless

            @if ($batch->status->isCommittable())
                <div class="alert alert-warning py-2 small">
                    {!! __('dpc.imports.preview.commit_warning', [
                        'template' => e($batch->template->label()),
                        'count' => $batch->total_rows - $batch->rejected_count,
                    ]) !!}
                    @if ($batch->rejected_count > 0)
{!! __('dpc.imports.preview.rejected_warning', ['count' => $batch->rejected_count]) !!}
                    @endif
                </div>
            @endif

            @if ($seniorityChanges !== null)
                {{-- Who keeps their recorded employee details, and whose are detached. --}}
                <div class="alert {{ $seniorityChanges['removed_with_details'] !== [] ? 'alert-danger' : 'alert-info' }} py-2 small" id="seniority-changes">
                    <div>{{ __('dpc.seniority_list.changes_summary', [
                        'kept' => $seniorityChanges['kept'],
                        'added' => count($seniorityChanges['added']),
                        'removed' => count($seniorityChanges['removed']),
                    ]) }}</div>
                    @if ($seniorityChanges['removed_with_details'] !== [])
                        <div class="mt-1">{{ __('dpc.seniority_list.changes_removed_with_details', ['officers' => implode('; ', $seniorityChanges['removed_with_details'])]) }}</div>
                    @endif
                    @if ($batch->rejected_count > 0)
                        <div class="mt-1 fw-semibold">{{ __('dpc.seniority_list.import_has_rejections', ['count' => $batch->rejected_count]) }}</div>
                    @endif
                </div>
            @endif

            <div class="d-flex flex-wrap gap-2 mb-3">
                @if ($batch->status->isCommittable() && $batch->rejected_count < $batch->total_rows
                    && ! ($batch->template === \App\Domain\Import\Enums\ImportTemplate::FinalSeniorityListUs && $batch->rejected_count > 0))
                    <form method="POST" action="{{ route('dpc-proposals.imports.commit', [$proposal, $batch]) }}"
                          data-confirm="Replace the {{ $batch->template->label() }} section with the valid rows from this file?">
                        @csrf{!! __('dpc.imports.preview.commit_rows', ['count' => $batch->total_rows - $batch->rejected_count]) !!}
                    </form>
                @endif

                @if ($batch->rejected_count > 0)
                    <a href="{{ route('dpc-proposals.imports.errors', [$proposal, $batch]) }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.imports.preview.download_error_report_csv') }}</a>
                @endif

                @if (! $batch->status->isTerminal())
                    <form method="POST" action="{{ route('dpc-proposals.imports.cancel', [$proposal, $batch]) }}">
                        @csrf<button class="btn btn-outline-danger btn-sm">{{ __('dpc.imports.preview.cancel_import') }}</button>
                    </form>
                @endif

                <a href="{{ route($batch->template->sectionRoute(), $proposal) }}" {{ __('ui.back_to_section_link') }}
            </div>

            @if ($batch->rejected_count > 0)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        {!! __('dpc.imports.preview.rejected_rows_heading', ['count' => $batch->rejected_count]) !!}
                        <p class="text-muted small">{{ __('dpc.imports.preview.row_numbers_refer_to_lines_in_your_uploaded_file') }}</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th style="width:8rem">{{ __('dpc.imports.preview.file_row') }}</th><th style="width:14rem">{{ __('ui.name') }}</th><th>{{ __('dpc.imports.preview.errors') }}</th></tr></thead>
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
                    {!! __('dpc.imports.preview.valid_rows_heading', ['count' => $batch->total_rows - $batch->rejected_count]) !!}
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th style="width:8rem">{{ __('dpc.imports.preview.file_row') }}</th><th>{{ __('ui.name') }}</th><th>{{ __('ui.dob') }}</th><th>{{ __('ui.category') }}</th><th>{{ __('dpc.imports.preview.details') }}</th></tr></thead>
                            <tbody>
                                @forelse ($validRows as $row)
                                    <tr>
                                        <td>{{ $row->row_number }}</td>
                                        <td>{{ $row->payload['name'] ?? '—' }}</td>
                                        <td>{{ $row->payload['dob'] ?? '—' }}</td>
                                        <td>{{ $categoryCodes[$row->payload['reservation_category_id'] ?? null] ?? '—' }}</td>
                                        <td class="small text-muted">
                                            @if (! empty($row->payload['cr_grades']))
                                                {{ __('dpc.imports.preview.cr_years', ['years' => collect($row->payload['cr_grades'])->pluck('year')->implode(', ')]) }}
                                            @elseif (isset($row->payload['seniority_number']))
                                                {{ __('dpc.seniority_list.preview_row', [
                                                    'number' => $row->payload['seniority_number'],
                                                    'employee' => $row->payload['employee_number'] ?? '—',
                                                    'post' => $row->payload['promotion_from_post'] ?? '—',
                                                    'appointed' => $row->payload['date_of_joining_service'] ?? '—',
                                                ]) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-3">{{ __('dpc.imports.preview.no_valid_rows_in_this_file') }}</td></tr>
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
