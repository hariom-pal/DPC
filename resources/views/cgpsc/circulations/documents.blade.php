@extends('layouts.app')

@section('title', 'Circulation documents')

@section('content')
    <div class="container py-4">
        <h1 class="h4 mb-1">Documents — Circulation {{ $circulation->circulation_code ?? '—' }}</h1>
        <p class="text-body-secondary">
            The prescribed form (परिभ्रमण टीप) and the revised promotion list, for origin DPC
            <strong>{{ $circulation->origin_dpc_code ?? '—' }}</strong>.
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

        <h2 class="h6">Current documents</h2>
        @if ($documents->isEmpty())
            <div class="alert alert-info">No document has been generated for this circulation yet.</div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Content hash</th>
                            <th>Signed</th>
                            <th>Published</th>
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
                                        <span class="text-body-secondary">Not published</span>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    @if ($document->isAvailable())
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('cgpsc.circulations.documents.download', [$circulation, $document]) }}">
                                            Download
                                        </a>
                                        <form method="POST" class="d-inline"
                                              action="{{ route('cgpsc.circulations.documents.verify', [$circulation, $document]) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">Verify</button>
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
                    <button class="btn btn-primary">Generate documents</button>
                </form>
            @endcan

            @can('manageDocuments', $circulation)
                <form method="POST" action="{{ route('cgpsc.circulations.documents.publish', $circulation) }}">
                    @csrf
                    <button class="btn btn-success" @disabled((bool) $pending)>
                        Publish to the department
                    </button>
                </form>
            @endcan
        </div>

        <h2 class="h6">Document history</h2>
        <p class="small text-body-secondary">
            Regenerating never overwrites — a new version is a new record, so what was issued and when
            stays answerable.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Version</th>
                        <th>Generated</th>
                        <th>By</th>
                        <th>Generator</th>
                        <th>Hash</th>
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
