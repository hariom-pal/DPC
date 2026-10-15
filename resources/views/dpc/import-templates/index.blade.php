@extends('layouts.app')

@section('title', __('dpc.import_templates.index.upload_templates'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('dpc.import_templates.index.upload_templates') }}</h1>
            <p class="text-muted small mb-3">
{!! __('dpc.import_templates.index.lead') !!}
            </p>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <form method="GET" action="{{ route('dpc-import-templates.index') }}" class="row g-2 align-items-end">
                        <div class="col-sm-4">
                            <label for="as_on" class="form-label small mb-1">{{ __('ui.promotion_as_on_year') }}</label>
                            <input type="number" id="as_on" name="as_on" class="form-control form-control-sm"
                                   value="{{ $asOnYear }}" min="1950" max="{{ now()->year + 5 }}">
                        </div>
                        <div class="col-sm-8">
                            <button class="btn btn-primary btn-sm">{{ __('dpc.import_templates.index.rebuild_templates_for_this_year') }}</button>
                            <span class="small text-muted d-block mt-1">
                                {{-- Only S18 has per-year columns, so only S18 changes. Said
                                     here so nobody wonders why the other two look identical. --}}
{!! __('dpc.import_templates.index.rebuild_note', ['years' => e(implode(', ', $years))]) !!}
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
{{ __('dpc.import_templates.index.download_csv') }}
                            </a>
                        </div>

                        <p class="small text-muted mt-2 mb-2">
{{ __('dpc.import_templates.index.columns_in_order', ['count' => count($builder->headers($template, $asOnYear))]) }}
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
                <strong class="d-block mb-1">{{ __('dpc.import_templates.index.two_things_worth_knowing_before_you_fill_one_in') }}</strong>
                <ul class="mb-0 ps-3">
                    <li>
                        {!! __('dpc.import_templates.index.cat_note') !!}
                    </li>
                    <li>
{!! __('dpc.import_templates.index.dates_note') !!}
                    </li>
                </ul>
            </div>
        </div>
    </div>
@endsection
