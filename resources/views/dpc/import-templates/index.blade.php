@extends('layouts.app')

@section('title', 'Upload Templates')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Upload Templates</h1>
            <p class="text-muted small mb-3">
                A blank file in exactly the shape the system accepts, for each of the three uploads.
                Fill it in and upload it on the corresponding section of your DPC proposal.
                <strong>Do not rename or reorder the columns</strong> — those are what the system reads.
            </p>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <form method="GET" action="{{ route('dpc-import-templates.index') }}" class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label for="as_on" class="form-label small mb-1">Promotion as on (year)</label>
                            <input type="number" id="as_on" name="as_on" class="form-control form-control-sm"
                                   value="{{ $asOnYear }}" min="1950" max="{{ now()->year + 5 }}">
                        </div>
                        <div class="col-sm-8">
                            <button class="btn btn-primary btn-sm">Rebuild templates for this year</button>
                            <span class="small text-muted d-block mt-1">
                                {{-- Only S18 has per-year columns, so only S18 changes. Said
                                     here so nobody wonders why the other two look identical. --}}
                                Affects the Evaluation Chart only — it is the one with per-year columns.
                                Years covered: <strong>{{ implode(', ', $years) }}</strong>.
                            </span>
                        </div>
                    </form>
                </div>
            </div>

            @foreach ($templates as $template)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <h2 class="h6 mb-0">{{ $template->label() }}</h2>
                            <a class="btn btn-outline-primary btn-sm"
                               href="{{ route('dpc-import-templates.download', ['template' => $template->value, 'as_on' => $asOnYear]) }}">
                                Download CSV
                            </a>
                        </div>

                        <p class="small text-muted mt-2 mb-2">
                            {{ count($builder->headers($template, $asOnYear)) }} columns, in this order:
                        </p>

                        <div class="d-flex flex-wrap gap-1">
                            @foreach ($builder->headers($template, $asOnYear) as $header)
                                <code class="small border rounded px-1">{{ $header }}</code>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="alert alert-secondary small mb-0">
                <strong class="d-block mb-1">Two things worth knowing before you fill one in</strong>
                <ul class="mb-0 ps-3">
                    <li>
                        <strong>CAT</strong> takes the category <em>code</em> — UR, SC, ST, OBC — not its full name.
                    </li>
                    <li>
                        Dates may be written as your spreadsheet prefers; the system reads Excel dates and
                        typed text alike. A missing CR or property year should be left <strong>blank</strong>,
                        not filled with "N" — a blank means "not recorded", and that is a different fact.
                    </li>
                </ul>
            </div>
        </div>
    </div>
@endsection
