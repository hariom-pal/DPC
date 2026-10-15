@extends('layouts.app')

@section('title', __('cgpsc.proceedings.documents.dpc_documents'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.proceedings.documents.proceedings_amp_promotion_list') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('cgpsc.proceedings.documents.the_commission_s_decision_was_taken_at_approval') }}
            </p>

            @error('document')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($run?->has_pending_decisions)
                <div class="alert alert-warning">
{!! __('cgpsc.proceedings.documents.provisional_explained') !!}
                </div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ui.document') }}</th><th>{{ __('ui.version') }}</th><th>{{ __('ui.status') }}</th>
                                    <th>{{ __('ui.generated') }}</th><th>{{ __('ui.signed') }}</th><th>{{ __('ui.published') }}</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($documents as $document)
                                <tr>
                                    <td>
                                        {{ $document->type->label() }}
                                        <div class="text-muted small">
                                            <code>{{ $document->uuid }}</code>
                                        </div>
                                    </td>
                                    <td>{{ $document->document_version }}</td>
                                    <td>
                                        <span class="badge {{ $document->status->badgeClass() }}">
                                            {{ $document->status->label() }}
                                        </span>
                                        @if ($document->failure_reason)
                                            <div class="text-danger small">{{ $document->failure_reason }}</div>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $document->generated_at?->format('d/m/Y h:i A') ?? '—' }}
                                        <div class="text-muted">{{ $document->generator_version }}</div>
                                    </td>
                                    <td>{!! $document->signature_id ? '&check;' : '&mdash;' !!}</td>
                                    <td class="small">
                                        {{ $document->published_at?->format('d/m/Y h:i A') ?? 'Not published' }}
                                    </td>
                                    <td>
                                        @if ($document->isAvailable())
                                            <a class="btn btn-sm btn-outline-primary" target="_blank"
                                               href="{{ route('cgpsc.proceedings.documents.show', [$proceeding, $document]) }}">
                                                {{ __('ui.open') }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-muted text-center py-3">
                                    {{ __('cgpsc.proceedings.documents.no_documents_have_been_generated_for_this_dpc_ye') }}
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($canAct || $proceeding->conducted_by === auth()->id())
                    <div class="card-footer bg-transparent d-flex gap-2">
                        <form method="POST" action="{{ route('cgpsc.proceedings.documents.store', $proceeding) }}">
                            @csrf
                            <button class="btn btn-sm btn-primary">
                                {{ $documents->isEmpty() ? 'Generate Documents' : 'Re-issue (new version)' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('cgpsc.proceedings.documents.publish', $proceeding) }}">
                            @csrf
                            <button class="btn btn-sm btn-success"
                                    @disabled($documents->isEmpty() || $run?->has_pending_decisions)>
{{ __('cgpsc.proceedings.documents.publish_to_department') }}
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            @if ($versions->count() > $documents->count())
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-2">{{ __('cgpsc.proceedings.documents.previous_versions') }}</h2>
                        <p class="text-muted small mb-2">
                            {{ __('cgpsc.proceedings.documents.a_re_issued_document_never_replaces_the_one_alre') }}
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>{{ __('ui.document') }}</th><th>{{ __('ui.version') }}</th><th>{{ __('ui.generated') }}</th><th>{{ __('ui.reference') }}</th></tr></thead>
                                <tbody>
                                @foreach ($versions as $version)
                                    <tr>
                                        <td>{{ $version->type->label() }}</td>
                                        <td>{{ $version->document_version }}</td>
                                        <td class="small">{{ $version->generated_at?->format('d/m/Y h:i A') ?? '—' }}</td>
                                        <td><code class="small">{{ $version->uuid }}</code></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-3">
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ route('cgpsc.proceedings.result', $proceeding) }}">{{ __('cgpsc.proceedings.documents.back_to_result') }}</a>
            </div>
        </div>
    </div>
@endsection
