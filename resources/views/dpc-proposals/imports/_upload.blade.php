{{--
    Excel upload widget for a proposal section (PPT S16–S18) — M4 Phase 6.
    Uploading only stages a preview; nothing is written until the officer
    confirms on the preview screen.

    Expects: $proposal, $template (ImportTemplate).
--}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 mb-1">{{ __('dpc.imports.upload.upload_from_excel') }}</h2>
        <p class="text-muted small mb-2">
{!! __('dpc.imports.upload.lead', ['template' => e($template->label())]) !!}
        </p>

        @error('file')<div class="alert alert-danger py-2 mb-2">{{ $message }}</div>@enderror

        <form method="POST" action="{{ route('dpc-proposals.imports.store', $proposal) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="template" value="{{ $template->value }}">
            <div class="col-md-7">
                <label class="form-label small">{{ __('dpc.imports.upload.spreadsheet_file') }}</label>
                <input type="file" class="form-control form-control-sm" name="file" required
                       accept=".xlsx,.xls,.csv,.txt">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">{{ __('dpc.imports.upload.upload_amp_preview') }}</button>
            </div>
        </form>

        {{-- TD-9, closed 2026-08-19 under the final-phase Freeze Exception.

             Recorded in the M11 Enhancement Backlog as the commonest cause of a
             rejected import: the columns were documented in prose below, so a
             department built the file from a written list and got a header wrong.

             The link is here rather than only on the Templates screen because this
             is where somebody is standing when they need it. `as_on` follows the
             proposal's own year, so the S18 per-year columns come out right without
             the officer having to think about it. One shared partial serves all
             three upload screens, so this is a single insertion rather than three. --}}
        {{-- The template for **this** DPC, so the two post columns arrive filled in
             and nobody types a post name from memory (business instruction,
             2026-09-06). The file also carries a `#` line under each column saying
             what it is and what format it takes; the importer skips `#` lines, so
             leaving them in place costs nothing. --}}
        <p class="small mt-2 mb-0">
{!! __('dpc.imports.upload.not_sure_of_the_columns', [
                'link' => '<a href="'.e(route('dpc-import-templates.download-for', [
                    'dpcProposal' => $proposal,
                    'template' => $template->value,
                ])).'">'.e(__('dpc.imports.upload.download_blank_template', ['template' => $template->label()])).'</a>',
            ]) !!}
        </p>

        {{-- The same guidance the file carries, on the screen (business instruction,
             2026-09-06: *"what goes in them, when, how, and what the correct format
             is — even I do not know"*). Rendered from
             `ImportTemplateBuilder::columnGuide()`, which is also what writes the
             `#` lines into the template, so the two cannot disagree. --}}
        @php
            $builder = app(\App\Domain\Import\Services\ImportTemplateBuilder::class);
            $guideHeaders = $builder->headers($template, optional($proposal->proposal_date)->year ?? now()->year);
            $guide = $builder->columnGuide();
        @endphp

        <details class="mt-2">
            <summary class="small text-primary" style="cursor:pointer">{{ __('dpc.imports.upload.what_goes_in_each_column') }}</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:16rem">{{ __('dpc.imports.upload.column') }}</th>
                            <th>{{ __('dpc.imports.upload.what_to_enter') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($guideHeaders as $header)
                            <tr>
                                <td class="font-monospace text-nowrap">{{ $header }}</td>
                                <td>
                                    @if (isset($guide[$header]))
                                        {{ $guide[$header] }}
                                    @elseif (\Illuminate\Support\Str::startsWith($header, 'CR '))
                                        {{ __('imports.guide.cr_year') }}
                                    @elseif (\Illuminate\Support\Str::startsWith($header, 'LOYALTY '))
                                        {{ __('imports.guide.loyalty_year') }}
                                    @elseif (\Illuminate\Support\Str::startsWith($header, 'IMMOVABLE PROPERTY '))
                                        {{ __('imports.guide.property_year') }}
                                    @elseif (\Illuminate\Support\Str::startsWith($header, 'TRIBAL AREA '))
                                        {{ __('imports.guide.tribal_year') }}
                                    @else
                                        <span class="text-body-secondary">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
{!! __('dpc.imports.upload.column_notes') !!}
        </details>
    </div>
</div>
