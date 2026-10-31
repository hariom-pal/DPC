@extends('layouts.app')
@section('title', __('dpc.verification.title'))
@section('content')
    <nav aria-label="{{ __('ui.screen.breadcrumb') }}">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item">
                <a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_code ?? __('dpc.record_gaps.proposal') }}</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('dpc.verification.breadcrumb') }}</li>
        </ol>
    </nav>

    <h1 class="h4 mb-1">{{ __('dpc.verification.title') }}</h1>
    <p class="text-muted mb-1">{{ __('dpc.verification.lead') }}</p>
    <p class="small text-muted mb-3">{{ __('dpc.verification.revision_note', ['revision' => $proposal->revision_number]) }}</p>

    @include('partials.alerts')

    @foreach ($status as $entry)
        @php
            $item = $entry['item'];
            $latest = $entry['latest'];
            $isPrevious = $item->value === 'previous_dpc_reconciliation';
        @endphp
        <div class="card border-0 shadow-sm mb-3" id="verification-{{ $item->value }}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <h2 class="h6 mb-1">{{ $item->label() }}</h2>
                        <p class="mb-2">{{ $item->statement() }}</p>
                    </div>
                    <div class="text-end flex-shrink-0">
                        @if ($entry['complete'])
                            <span class="badge text-bg-success">{{ __('dpc.verification.status_confirmed') }}</span>
                        @elseif ($entry['stale'])
                            <span class="badge text-bg-warning">{{ __('dpc.verification.status_stale') }}</span>
                        @elseif ($latest !== null)
                            <span class="badge text-bg-secondary">{{ __('dpc.verification.status_withdrawn') }}</span>
                        @else
                            <span class="badge text-bg-light text-dark">{{ __('dpc.verification.not_recorded') }}</span>
                        @endif
                        @if ($latest !== null)
                            <div class="small text-muted mt-1">
                                {{ __('dpc.verification.by_at', [
                                    'name' => $latest->verifier?->name ?? '—',
                                    'at' => $latest->verified_at->format('d-m-Y H:i'),
                                ]) }}
                            </div>
                        @endif
                    </div>
                </div>

                {{-- The figure being confirmed, read from where the proposal keeps it. --}}
                <div class="small text-muted mb-2">
                    <strong>{{ __('dpc.verification.checked_against') }}:</strong>
                    @switch ($item->value)
                        @case('sanctioned_strength')
                            {{ $summary['sanctioned'] === null
                                ? __('dpc.verification.figures.sanctioned_missing')
                                : __('dpc.verification.figures.sanctioned', ['value' => $summary['sanctioned']]) }}
                            @break
                        @case('recruitment_rule')
                            {{ $rule === null
                                ? __('dpc.verification.figures.rule_missing')
                                : __('dpc.verification.figures.rule', [
                                    'version' => $rule->version,
                                    'go' => $rule->government_order_number,
                                ]) }}
                            @break
                        @case('seniority_list')
                            {{ __('dpc.verification.figures.seniority', ['count' => $summary['seniority_entered']]) }}
                            @break
                        @case('representations')
                            {{ __('dpc.verification.figures.representations', ['count' => $representations->count()]) }}
                            @break
                        @case('original_documents')
                            {{ __('dpc.verification.figures.original_documents') }}
                            @break
                        @case('recruitment_rule_conformity')
                            {{ $rule === null
                                ? __('dpc.verification.figures.rule_missing')
                                : __('dpc.verification.figures.rule', ['version' => $rule->version, 'go' => $rule->government_order_number]) }}
                            @if ($latest?->rr_notified_on && $entry['confirmed'])
                                — {{ __('dpc.verification.figures.rr_notified_on', ['date' => $latest->rr_notified_on->format('d-m-Y')]) }}
                            @endif
                            @break
                        @default
                            {{ __('dpc.verification.figures.working', ['count' => $summary['working']]) }}
                    @endswitch
                </div>

                @if ($entry['stale'])
                    <div class="alert alert-warning py-2 small">{{ __('dpc.verification.stale_help') }}</div>
                @endif

                @if ($item->value === 'representations')
                    {{-- Rule 4.4: each representation or complaint, with its summary. --}}
                    @error('representation')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                    @if ($representations->isNotEmpty())
                        <div class="table-responsive mb-2">
                            <table class="table table-sm small mb-0" id="representations-list">
                                <thead><tr>
                                    <th>{{ __('dpc.representations.received_from') }}</th>
                                    <th>{{ __('dpc.representations.received_on') }}</th>
                                    <th>{{ __('dpc.representations.summary') }}</th>
                                    <th>{{ __('dpc.representations.document') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($representations as $representation)
                                        <tr>
                                            <td>{{ $representation->received_from ?? '—' }}</td>
                                            <td>{{ $representation->received_on?->format('d-m-Y') ?? '—' }}</td>
                                            <td>{{ $representation->summary }}</td>
                                            <td><a href="{{ route('dpc-proposals.representations.download', [$proposal, $representation]) }}">{{ $representation->original_name }}</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if ($canEdit)
                        <form method="POST" enctype="multipart/form-data" class="border rounded p-2 mb-2 row g-2 align-items-end"
                              action="{{ route('dpc-proposals.representations.store', $proposal) }}">
                            @csrf
                            <div class="col-md-4">
                                <label class="form-label small" for="received_from">{{ __('dpc.representations.received_from') }}</label>
                                <input type="text" class="form-control form-control-sm" id="received_from" name="received_from" maxlength="255">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small" for="received_on">{{ __('dpc.representations.received_on') }}</label>
                                <input type="date" class="form-control form-control-sm" id="received_on" name="received_on">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small" for="representation-document">{{ __('dpc.representations.document') }}</label>
                                <input type="file" accept="application/pdf" class="form-control form-control-sm" id="representation-document" name="document" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small" for="representation-summary">{{ __('dpc.representations.summary') }}</label>
                                <textarea class="form-control form-control-sm" id="representation-summary" name="summary" rows="2" maxlength="2000" required></textarea>
                            </div>
                            <div class="col-12"><button class="btn btn-outline-primary btn-sm">{{ __('dpc.representations.upload') }}</button></div>
                        </form>
                    @endif
                @endif

                @if ($latest?->previous_dpc_basis && $entry['confirmed'])
                    <div class="small mb-2">
                        <strong>{{ __('dpc.verification.previous_dpc.heading') }}:</strong>
                        {{ $latest->previous_dpc_basis->label() }}
                        @if ($latest->previous_dpc_reference) — {{ $latest->previous_dpc_reference }} @endif
                        @if ($latest->previous_dpc_held_on) — {{ $latest->previous_dpc_held_on->format('d-m-Y') }} @endif
                        @if ($latest->hasDocument())
                            — <a href="{{ route('dpc-proposals.verification.minutes', [$proposal, $latest]) }}">{{ $latest->original_name }}</a>
                        @endif
                    </div>
                @endif

                @if ($canEdit)
                    @if ($entry['confirmed'])
                        <form method="POST" class="d-inline"
                              action="{{ route('dpc-proposals.verification.destroy', [$proposal, $item->value]) }}"
                              data-confirm="{{ __('dpc.verification.withdraw_confirm') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">{{ __('dpc.verification.withdraw_button') }}</button>
                        </form>
                    @endif

                    @if (! $entry['complete'])
                        <form method="POST" enctype="multipart/form-data" class="mt-2"
                              action="{{ route('dpc-proposals.verification.store', [$proposal, $item->value]) }}">
                            @csrf

                            @if ($isPrevious)
                                <fieldset class="mb-3">
                                    <legend class="form-label fs-6">{{ __('dpc.verification.previous_dpc.basis_label') }}</legend>
                                    @foreach ($bases as $basis)
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="previous_dpc_basis"
                                                   id="basis-{{ $basis->value }}" value="{{ $basis->value }}"
                                                   @checked(old('previous_dpc_basis') === $basis->value)>
                                            <label class="form-check-label" for="basis-{{ $basis->value }}">{{ $basis->label() }}</label>
                                        </div>
                                    @endforeach
                                    @error('previous_dpc_basis')<div class="text-danger small">{{ $message }}</div>@enderror
                                </fieldset>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="previous_dpc_proposal_id">{{ __('dpc.verification.previous_dpc.proposal') }}</label>
                                        @if ($previousCandidates->isEmpty())
                                            <div class="form-text">{{ __('dpc.verification.previous_dpc.no_candidates') }}</div>
                                        @else
                                            <select class="form-select @error('previous_dpc_proposal_id') is-invalid @enderror"
                                                    id="previous_dpc_proposal_id" name="previous_dpc_proposal_id">
                                                <option value="">{{ __('dpc.verification.previous_dpc.choose_proposal') }}</option>
                                                @foreach ($previousCandidates as $candidate)
                                                    <option value="{{ $candidate->id }}" @selected((int) old('previous_dpc_proposal_id') === $candidate->id)>
                                                        {{ $candidate->dpc_code }} ({{ $candidate->dpc_year }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('previous_dpc_proposal_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        @endif
                                    </div>
                                </div>

                                @if ($previousRecords->isNotEmpty())
                                    {{-- Rule 1.1: a previous DPC the Commission's staff recorded. --}}
                                    <div class="mb-3 col-md-8">
                                        <label class="form-label" for="previous_dpc_record_id">{{ __('previous_dpc.verification.record') }}</label>
                                        <select class="form-select @error('previous_dpc_record_id') is-invalid @enderror" id="previous_dpc_record_id" name="previous_dpc_record_id">
                                            <option value="">{{ __('previous_dpc.verification.none') }}</option>
                                            @foreach ($previousRecords as $previousRecord)
                                                <option value="{{ $previousRecord->id }}" @selected((int) old('previous_dpc_record_id') === $previousRecord->id)>
                                                    {{ $previousRecord->reference }} — {{ $previousRecord->held_on->format('d-m-Y') }} ({{ $previousRecord->dpc_year }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="form-text">{{ __('previous_dpc.verification.record_help') }}</div>
                                        @error('previous_dpc_record_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                @endif

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label" for="previous_dpc_reference">{{ __('dpc.verification.previous_dpc.reference') }}</label>
                                        <input type="text" maxlength="120" class="form-control @error('previous_dpc_reference') is-invalid @enderror"
                                               id="previous_dpc_reference" name="previous_dpc_reference" value="{{ old('previous_dpc_reference') }}">
                                        @error('previous_dpc_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label" for="previous_dpc_held_on">{{ __('dpc.verification.previous_dpc.held_on') }}</label>
                                        <input type="date" class="form-control @error('previous_dpc_held_on') is-invalid @enderror"
                                               id="previous_dpc_held_on" name="previous_dpc_held_on" value="{{ old('previous_dpc_held_on') }}">
                                        @error('previous_dpc_held_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label" for="minutes">{{ __('dpc.verification.previous_dpc.minutes') }}</label>
                                        <input type="file" accept="application/pdf" class="form-control @error('minutes') is-invalid @enderror"
                                               id="minutes" name="minutes">
                                        @error('minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            @endif

                            @if ($item->value === 'recruitment_rule_conformity')
                                <div class="mb-2 col-md-4">
                                    <label class="form-label" for="rr_notified_on">{{ __('dpc.verification.items.recruitment_rule_conformity.date_label') }}</label>
                                    <input type="date" class="form-control @error('rr_notified_on') is-invalid @enderror" id="rr_notified_on" name="rr_notified_on" required
                                           value="{{ old('rr_notified_on') }}">
                                    @error('rr_notified_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endif

                            <div class="mb-2">
                                <label class="form-label" for="remarks-{{ $item->value }}">{{ __('dpc.verification.remarks') }}</label>
                                <textarea class="form-control" id="remarks-{{ $item->value }}" name="remarks" rows="2" maxlength="2000"></textarea>
                                <div class="form-text">{{ __('dpc.verification.remarks_help') }}</div>
                            </div>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm-{{ $item->value }}" required>
                                <label class="form-check-label" for="confirm-{{ $item->value }}">{{ __('dpc.verification.confirm_label') }}</label>
                            </div>

                            <button class="btn btn-primary btn-sm">
                                {{ $entry['stale'] ? __('dpc.verification.reconfirm_button') : __('dpc.verification.confirm_button') }}
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    @endforeach

    {{-- Every statement ever made about this proposal, across revisions. --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">{{ __('dpc.verification.history_heading') }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle small">
                <thead>
                    <tr>
                        <th>{{ __('dpc.verification.col_revision') }}</th>
                        <th>{{ __('dpc.verification.col_item') }}</th>
                        <th>{{ __('dpc.verification.col_status') }}</th>
                        <th>{{ __('dpc.verification.col_reference') }}</th>
                        <th>{{ __('dpc.verification.col_remarks') }}</th>
                        <th>{{ __('dpc.verification.col_by') }}</th>
                        <th>{{ __('dpc.verification.col_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $row)
                        <tr>
                            <td>{{ $row->revision_number }}</td>
                            <td>{{ $row->item->label() }}</td>
                            <td>{{ $row->isConfirmed() ? __('dpc.verification.status_confirmed') : __('dpc.verification.status_withdrawn') }}</td>
                            <td>
                                @if ($row->previous_dpc_basis)
                                    {{ $row->previous_dpc_basis->label() }}
                                    @if ($row->previous_dpc_reference) — {{ $row->previous_dpc_reference }} @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $row->remarks ?: '—' }}</td>
                            <td>{{ $row->verifier?->name ?? '—' }}</td>
                            <td>{{ $row->verified_at->format('d-m-Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-3">{{ __('dpc.verification.history_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
