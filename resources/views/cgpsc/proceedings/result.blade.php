@extends('layouts.app')

@section('title', 'DPC Result — Approval')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-12">
            @include('partials.alerts')

            <h1 class="h4 mb-1">Online DPC — Approval</h1>
            <p class="text-muted small mb-3">
                {{ $proceeding->proposal->postFromCadre?->name }} →
                {{ $proceeding->proposal->postToCadre?->name }},
                {{ $proceeding->proposal->department?->name }}
                · DPC ID <code>{{ $proceeding->proposal->dpc_code ?? '—' }}</code>
            </p>

            @error('result')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('promotion_as_on_year')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($run === null)
                <div class="alert alert-secondary">
                    This DPC has not been calculated yet.
                    @if ($canAct)
                        <form method="POST" action="{{ route('cgpsc.proceedings.calculate', $proceeding) }}"
                              class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-primary ms-2">CALCULATE</button>
                        </form>
                    @endif
                </div>
            @else
                @if ($isStale)
                    {{-- AQ-2: a result that does not reflect the last correction must never be approved. --}}
                    <div class="alert alert-warning">
                        <strong>Corrections have been recorded since this result was calculated.</strong>
                        @if ($staleCorrections->isNotEmpty())
                            Affected: {{ $staleCorrections->pluck('officer_name')->unique()->implode(', ') }}.
                        @endif
                        Calculate again and review the result before approving it.
                    </div>
                @endif

                @if ($run->has_pending_decisions)
                    {{-- Directive 5: an unresolved business rule is reported, never resolved in code. --}}
                    <div class="alert alert-warning">
                        <strong>This result is provisional.</strong>
                        A business rule that would affect it has not been settled, so the system has applied the
                        printed rule and flagged the cases rather than guessing.
                        <ul class="mb-0 mt-1 small">
                            @foreach ((array) $run->pending_decisions as $pending)
                                <li>{{ $pending }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="row g-3 small">
                            <div class="col-md-3">
                                <div class="text-muted">Calculation</div>
                                <code>{{ $run->uuid }}</code>
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted">Rule Version</div>
                                {{ $run->rule_version }}
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted">Officers Considered</div>
                                {{ $run->officers_considered }}
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted">Calculated At</div>
                                {{ $run->executed_at?->format('d/m/Y h:i A') }}
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted">Status</div>
                                <span class="badge {{ $proceeding->status->badgeClass() }}">
                                    {{ $proceeding->status->label() }}
                                </span>
                                @if ($proceeding->closed_at)
                                    <span class="small">
                                        closed by {{ $proceeding->closed_by_name }},
                                        {{ $proceeding->closed_at->format('d/m/Y h:i A') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                @php
                    $heading = ($proceeding->promotion_as_on_year ?? $defaultYear)
                        .' — '.$proceeding->proposal->postToCadre?->name
                        .' FROM '.$proceeding->proposal->postFromCadre?->name;
                @endphp

                @foreach ([['main', 'MAIN LIST (मुख्य सूचि)'], ['waiting', 'WAITING LIST (अनुपूरक सूचि)']] as [$key, $title])
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-1">PROMOTION AS ON {{ $heading }}</h2>
                            <div class="text-muted small mb-2">{{ $title }}</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SN</th><th>Name</th><th>Category</th>
                                            <th>Mark</th><th>Remark</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($lists[$key] as $index => $result)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td>{{ $result->officer_name }}</td>
                                            <td>{{ $result->reservation_category_code }}</td>
                                            <td class="text-center">
                                                {{ $result->score_breakdown['total'] ?? '—' }}
                                            </td>
                                            <td>
                                                <strong>{{ $result->remark }}</strong>
                                                @if ($result->is_super_seat)
                                                    <span class="badge text-bg-info">SS</span>
                                                @endif
                                                @if ($holds->has($result->sn))
                                                    {{-- S34's "ST SS CIR": a hold is composed onto the
                                                         remark, not produced by the frozen engine. --}}
                                                    <span class="badge text-bg-warning">CIR</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-muted text-center py-2">
                                            No officers on this list.
                                        </td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if ($lists['others']->isNotEmpty())
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-2">Not Listed</h2>
                            <p class="text-muted small mb-2">
                                Every officer on the chart has a result. An officer appears here when they were
                                not eligible, fell outside the zone of consideration, or their category had no
                                vacancy — so an absence from both lists is explained rather than silent.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>SN</th><th>Name</th><th>Category</th><th>Mark</th><th>Remark</th></tr></thead>
                                    <tbody>
                                    @foreach ($lists['others'] as $result)
                                        <tr>
                                            <td>{{ $result->sn }}</td>
                                            <td>{{ $result->officer_name }}</td>
                                            <td>{{ $result->reservation_category_code }}</td>
                                            <td class="text-center">{{ $result->score_breakdown['total'] ?? '—' }}</td>
                                            <td>{{ $result->remark }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($holds->isNotEmpty())
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-2">Promotions Held (परिभ्रमण / CIR)</h2>
                            <p class="text-muted small mb-2">
                                These officers took part in the DPC, but the promotion action in respect of each
                                is <strong>reserved</strong> until the matter noted is resolved. A hold is not a
                                finding on merit — an officer can be top-scoring and still held.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>SN</th><th>Officer</th><th>Reason</th><th>Detail</th><th>Source</th>@if ($canAct)<th></th>@endif</tr></thead>
                                    <tbody>
                                    @foreach ($holds as $officerHolds)
                                        @foreach ($officerHolds as $hold)
                                            <tr>
                                                <td>{{ $hold->sn }}</td>
                                                <td>{{ $hold->officer_name }}</td>
                                                <td>{{ $hold->reason_code->label() }}</td>
                                                <td class="small">{{ $hold->detail }}</td>
                                                <td class="small">
                                                    {{ $hold->is_automatic ? 'From the chart' : $hold->recorded_by_name }}
                                                </td>
                                                @if ($canAct)
                                                    <td>
                                                        @unless ($hold->is_automatic)
                                                            <form method="POST"
                                                                  action="{{ route('cgpsc.proceedings.holds.destroy', [$proceeding, $hold]) }}">
                                                                @csrf @method('DELETE')
                                                                <button class="btn btn-sm btn-outline-secondary py-0">Release</button>
                                                            </form>
                                                        @endunless
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($canAct)
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
                            <form method="POST" action="{{ route('cgpsc.proceedings.calculate', $proceeding) }}">
                                @csrf
                                <button class="btn btn-outline-primary">CALCULATE AGAIN</button>
                            </form>

                            <form method="POST" action="{{ route('cgpsc.proceedings.approve', $proceeding) }}"
                                  class="d-flex gap-2 align-items-end">
                                @csrf
                                <div>
                                    <label class="form-label small mb-0">Promotion as on (year)</label>
                                    <input type="number" name="promotion_as_on_year" class="form-control form-control-sm"
                                           value="{{ old('promotion_as_on_year', $defaultYear) }}" style="width:9rem">
                                </div>
                                <button class="btn btn-success" @disabled($isStale)>APPROVE BY CGPSC</button>
                            </form>

                            <form method="POST" action="{{ route('cgpsc.proceedings.cancel', $proceeding) }}"
                                  class="d-flex gap-2 align-items-end ms-auto">
                                @csrf
                                <div>
                                    <label class="form-label small mb-0">Reason</label>
                                    <input type="text" name="reason" class="form-control form-control-sm"
                                           style="width:18rem" placeholder="Why this DPC is being cancelled">
                                </div>
                                <button class="btn btn-outline-danger">CANCEL DPC</button>
                            </form>
                        </div>
                        <div class="card-footer bg-transparent small text-muted">
                            Approving pins this calculation permanently. A later calculation cannot change what was
                            approved, and an approved result cannot be edited by any role — a correction afterwards
                            is a Review DPC.
                        </div>
                    </div>
                @endif

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-2">Calculation History</h2>
                        <p class="text-muted small mb-2">
                            Every calculation ever run for this DPC. Nothing is overwritten — recalculating creates
                            a new record and leaves the previous one untouched.
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>Reference</th><th>Run At</th><th>By</th><th>Main</th><th>Waiting</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($history as $entry)
                                    <tr>
                                        <td><code class="small">{{ $entry->uuid }}</code></td>
                                        <td>{{ $entry->executed_at?->format('d/m/Y h:i A') }}</td>
                                        <td>{{ $entry->executedBy?->name ?? 'system' }}</td>
                                        <td>{{ $entry->main_list_count }}</td>
                                        <td>{{ $entry->waiting_list_count }}</td>
                                        <td>
                                            @if ($proceeding->final_calculation_run_id === $entry->id)
                                                <span class="badge text-bg-success">Approved</span>
                                            @elseif ($proceeding->latest_calculation_run_id === $entry->id)
                                                <span class="badge text-bg-primary">Current</span>
                                            @endif
                                        </td>
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
                   href="{{ route('cgpsc.proceedings.show', $proceeding) }}">Back to Workspace</a>
                <a class="btn btn-sm btn-outline-primary"
                   href="{{ route('cgpsc.proceedings.documents.index', $proceeding) }}">Documents</a>
                <a class="btn btn-sm btn-link"
                   href="{{ route('cgpsc.proceedings.history', $proceeding) }}">Proceeding History</a>
            </div>
        </div>
    </div>
@endsection
