@extends('layouts.app')

@section('title', __('cgpsc.circulations.documents.circulation_documents'))

@section('content')
    <div class="container py-4">
        {!! __('cgpsc.circulations.documents.heading', ['code' => e($circulation->circulation_code ?? '—')]) !!}
        <p class="text-body-secondary">
{!! __('cgpsc.circulations.documents.lead', ['code' => e($circulation->origin_dpc_code ?? '—')]) !!}
        </p>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- §23.1 — the refusal is shown up front, not discovered on the
             publish button. A document that never publishes with no reason
             given is indistinguishable from a fault. --}}
        @php $blocked = session('pendingBusinessDecision') ?? $pending; @endphp
        @if ($blocked)
            @include('dpc.circulations._pending-business-decision', ['pending' => $blocked])
        @endif

        <h2 class="h6">{{ __('cgpsc.circulations.documents.current_documents') }}</h2>
        @if ($documents->isEmpty())
            <div class="alert alert-info">{{ __('cgpsc.circulations.documents.none_generated') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('ui.document') }}</th>
                            <th>{{ __('ui.version') }}</th>
                            <th>{{ __('ui.status') }}</th>
                            <th>{{ __('cgpsc.circulations.documents.content_hash') }}</th>
                            <th>{{ __('ui.signed') }}</th>
                            <th>{{ __('ui.published') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td>{{ $document->type->label() }}</td>
                                <td>v{{ $document->document_version }}</td>
                                <td>{{ $document->status->label() }}</td>
                                <td><code class="small">{{ Str::limit($document->content_hash ?? '—', 16) }}</code></td>
                                <td>{{ $document->signature_id ? 'Yes' : 'No' }}</td>
                                <td>
                                    @if ($document->isPublished())
                                        {{ $document->published_at->format('d-m-Y H:i') }}
                                    @else
                                        <span class="text-body-secondary">{{ __('cgpsc.circulations.documents.not_published') }}</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($document->isAvailable())
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('cgpsc.circulations.documents.download', [$circulation, $document]) }}">
                                            {{ __('cgpsc.circulations.documents.download') }}
                                        </a>
                                        <form method="POST" class="d-inline"
                                              action="{{ route('cgpsc.circulations.documents.verify', [$circulation, $document]) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">{{ __('cgpsc.circulations.documents.verify') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="d-flex gap-2 mb-4">
            @can('manageDocuments', $circulation)
                <form method="POST" action="{{ route('cgpsc.circulations.documents.store', $circulation) }}">
                    @csrf
                    <button class="btn btn-primary">{{ __('cgpsc.circulations.documents.generate_documents') }}</button>
                </form>
            @endcan

            @can('manageDocuments', $circulation)
                <form method="POST" action="{{ route('cgpsc.circulations.documents.publish', $circulation) }}">
                    @csrf
                    <button class="btn btn-success" @disabled((bool) $pending)>
                        {{ __('cgpsc.circulations.documents.publish_to_the_department') }}
                    </button>
                </form>
            @endcan
        </div>

        <h2 class="h6">{{ __('cgpsc.circulations.documents.document_history') }}</h2>
        <p class="small text-body-secondary">
            {{ __('cgpsc.circulations.documents.regenerating_never_overwrites_a_new_version_is_a') }}
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.document') }}</th>
                        <th>{{ __('ui.version') }}</th>
                        <th>{{ __('ui.generated') }}</th>
                        <th>{{ __('ui.by') }}</th>
                        <th>{{ __('cgpsc.circulations.documents.generator') }}</th>
                        <th>{{ __('cgpsc.circulations.documents.hash') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $document)
                        <tr>
                            <td>{{ $document->type->label() }}</td>
                            <td>v{{ $document->document_version }}</td>
                            <td>{{ $document->generated_at?->format('d-m-Y H:i') ?? '—' }}</td>
                            <td>{{ $document->generated_by_name ?? '—' }}</td>
                            <td><code class="small">{{ $document->generator_version }}</code></td>
                            <td><code class="small">{{ Str::limit($document->content_hash ?? '—', 16) }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
