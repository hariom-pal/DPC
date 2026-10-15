@extends('layouts.admin')

@section('title', __('admin.pre_dpc_links.index.heading'))

@section('content')
    <h1 class="h4 mb-1">{{ __('admin.pre_dpc_links.index.heading') }}</h1>
    <p class="text-muted small mb-3">{{ __('admin.pre_dpc_links.index.lead') }}</p>

    @include('partials.alerts')

    @forelse ($proposals as $proposal)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <h2 class="h6 mb-1"><code>{{ $proposal->dpc_code ?? '—' }}</code></h2>
                        <div class="small text-body-secondary">
                            {{ $proposal->department?->name ?? '—' }} ·
                            {{ $proposal->promotionFromPost?->name ?? '—' }} →
                            {{ $proposal->promotionToPost?->name ?? '—' }}
                            @if ($proposal->dpc_year)· {{ $proposal->dpc_year }}@endif
                        </div>
                    </div>
                    <span class="badge {{ $proposal->status->badgeClass() }}">{{ $proposal->status->label() }}</span>
                </div>

                @if (($candidates[$proposal->id] ?? collect())->isEmpty())
                    {{-- No arguable order. Said plainly rather than shown as an
                         empty dropdown, which reads as a broken screen. --}}
                    <p class="small text-body-secondary mb-0">{{ __('admin.pre_dpc_links.index.no_candidate') }}</p>
                @else
                    <form method="POST" action="{{ route('admin.pre-dpc-links.store', $proposal) }}"
                          class="row g-2 align-items-end"
                          data-confirm="{{ __('admin.pre_dpc_links.index.confirm') }}">
                        @csrf
                        <div class="col-md-8">
                            <label class="form-label small" for="pre_dpc_{{ $proposal->id }}">
                                {{ __('admin.pre_dpc_links.index.sanctioning_order') }}
                            </label>
                            <select class="form-select form-select-sm" name="pre_dpc_proposal_id"
                                    id="pre_dpc_{{ $proposal->id }}" required>
                                <option value="">{{ __('ui.select') }}</option>
                                @foreach ($candidates[$proposal->id] as $candidate)
                                    <option value="{{ $candidate->id }}">
                                        {{ $candidate->pre_dpc_number ?? '—' }}
                                        · {{ __('ui.version_n', ['number' => $candidate->version]) }}
                                        · {{ $candidate->status->label() }}
                                        @if ($candidate->government_order_number)
                                            · {{ __('ui.go_number', ['number' => $candidate->government_order_number]) }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary btn-sm">{{ __('admin.pre_dpc_links.index.record') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="alert alert-success mb-0">{{ __('admin.pre_dpc_links.index.nothing_unlinked') }}</div>
    @endforelse
@endsection
