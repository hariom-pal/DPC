@extends('layouts.app')

@section('title', __('cgpsc.proceedings.result.dpc_result_approval'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-12">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.proceedings.result.online_dpc_approval') }}</h1>
            <p class="text-muted small mb-3">
{!! __('cgpsc.proceedings.result.subheading', [
                    'from' => e($proceeding->proposal->promotionFromPost?->name),
                    'to' => e($proceeding->proposal->promotionToPost?->name),
                    'department' => e($proceeding->proposal->department?->name),
                    'code' => e($proceeding->proposal->dpc_code ?? '—'),
                ]) !!}
            </p>

            @error('result')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
            @error('promotion_as_on_year')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            @if ($run === null)
                <div class="alert alert-secondary">
{{ __('cgpsc.proceedings.result.not_calculated_yet') }}
                    @if ($canAct)
                        <form method="POST" action="{{ route('cgpsc.proceedings.calculate', $proceeding) }}"
                              class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-primary ms-2">{{ __('ui.calculate') }}</button>
                        </form>
                    @endif
                </div>
            @else
                @if ($isStale)
                    {{-- AQ-2: a result that does not reflect the last correction must never be approved. --}}
                    <div class="alert alert-warning">
                        <strong>{{ __('cgpsc.proceedings.result.corrections_have_been_recorded_since_this_result') }}</strong>
                        @if ($staleCorrections->isNotEmpty())
{{ __('cgpsc.proceedings.result.affected_officers', ['officers' => $staleCorrections->pluck('officer_name')->unique()->implode(', ')]) }}
                        @endif
                        {{ __('cgpsc.proceedings.result.calculate_again') }}
                    </div>
                @endif

                @if ($run->has_pending_decisions)
                    {{-- Directive 5: an unresolved business rule is reported, never resolved in code. --}}
                    <div class="alert alert-warning">
{!! __('cgpsc.proceedings.result.provisional_explained') !!}
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
                                <div class="text-muted">{{ __('cgpsc.proceedings.result.calculation') }}</div>
                                <code>{{ $run->uuid }}</code>
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted">{{ __('cgpsc.proceedings.result.rule_version') }}</div>
                                {{ $run->rule_version }}
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted">{{ __('cgpsc.proceedings.result.officers_considered') }}</div>
                                {{ $run->officers_considered }}
                            </div>
                            <div class="col-md-2">
                                <div class="text-muted">{{ __('cgpsc.proceedings.result.calculated_at') }}</div>
                                {{ $run->executed_at?->format('d/m/Y h:i A') }}
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted">{{ __('cgpsc.proceedings.result.status') }}</div>
                                <span class="badge {{ $proceeding->status->badgeClass() }}">
                                    {{ $proceeding->status->label() }}
                                </span>
                                @if ($proceeding->closed_at)
                                    <span class="small">
{{ __('cgpsc.proceedings.result.closed_by', ['actor' => $proceeding->closed_by_name, 'at' => $proceeding->closed_at->format('d/m/Y h:i A')]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                @php
                    $heading = ($proceeding->promotion_as_on_year ?? $defaultYear)
                        .' — '.$proceeding->proposal->promotionToPost?->name
                        .' FROM '.$proceeding->proposal->promotionFromPost?->name;
                @endphp

                @foreach ([['main', __('cgpsc.proceedings.result.main_list')], ['waiting', __('cgpsc.proceedings.result.waiting_list')]] as [$key, $title])
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            {!! __('cgpsc.proceedings.result.promotion_as_on', ['date' => e($heading)]) !!}
                            <div class="text-muted small mb-2">{{ $title }}</div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ __('ui.sn') }}</th><th>{{ __('ui.name') }}</th><th>{{ __('ui.category') }}</th>
                                            <th>{{ __('ui.mark') }}</th><th>{{ __('ui.remark') }}</th>
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
                                                    <span class="badge text-bg-info">{{ __('ui.ss') }}</span>
                                                @endif
                                                @if ($holds->has($result->sn))
                                                    {{-- S34's "ST SS CIR": a hold is composed onto the
                                                         remark, not produced by the frozen engine. --}}
                                                    <span class="badge text-bg-warning">{{ __('ui.cir') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-muted text-center py-2">
                                            {{ __('cgpsc.proceedings.result.no_officers_on_this_list') }}
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
                            <h2 class="h6 mb-2">{{ __('cgpsc.proceedings.result.not_listed') }}</h2>
                            <p class="text-muted small mb-2">
                                {{ __('cgpsc.proceedings.result.every_officer_on_the_chart_has_a_result_an_offic') }}
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>{{ __('ui.sn') }}</th><th>{{ __('ui.name') }}</th><th>{{ __('ui.category') }}</th><th>{{ __('ui.mark') }}</th><th>{{ __('ui.remark') }}</th></tr></thead>
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
                            <h2 class="h6 mb-2">{{ __('cgpsc.proceedings.result.promotions_held_cir') }}</h2>
                            <p class="text-muted small mb-2">
{!! __('cgpsc.proceedings.result.holds_explained') !!}
                            </p>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>{{ __('ui.sn') }}</th><th>{{ __('ui.officer') }}</th><th>{{ __('ui.reason') }}</th><th>{{ __('ui.detail') }}</th><th>{{ __('cgpsc.proceedings.result.source') }}</th>@if ($canAct)<th></th>@endif</tr></thead>
                                    <tbody>
                                    @foreach ($holds as $officerHolds)
                                        @foreach ($officerHolds as $hold)
                                            <tr>
                                                <td>{{ $hold->sn }}</td>
                                                <td>{{ $hold->officer_name }}</td>
                                                <td>{{ $hold->reason_code->label() }}</td>
                                                <td class="small">{{ $hold->detail }}</td>
                                                <td class="small">
                                                    {{ $hold->is_automatic ? __('ui.screen.from_the_chart') : $hold->recorded_by_name }}
                                                </td>
                                                @if ($canAct)
                                                    <td>
                                                        @unless ($hold->is_automatic)
                                                            <form method="POST"
                                                                  action="{{ route('cgpsc.proceedings.holds.destroy', [$proceeding, $hold]) }}">
                                                                @csrf @method('DELETE')
                                                                <button class="btn btn-sm btn-outline-danger py-0">{{ __('cgpsc.proceedings.result.release') }}</button>
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
                                <button class="btn btn-outline-primary">{{ __('cgpsc.proceedings.result.calculate_again') }}</button>
                            </form>

                            <form method="POST" action="{{ route('cgpsc.proceedings.approve', $proceeding) }}"
                                  class="d-flex gap-2 align-items-end">
                                @csrf
                                <div>
                                    <label class="form-label small mb-0">{{ __('ui.promotion_as_on_year') }}</label>
                                    <input type="number" name="promotion_as_on_year" class="form-control form-control-sm"
                                           value="{{ old('promotion_as_on_year', $defaultYear) }}" style="width:9rem">
                                </div>
                                <button class="btn btn-success" @disabled($isStale)>{{ __('ui.approve_by_cgpsc') }}</button>
                            </form>

                            <form method="POST" action="{{ route('cgpsc.proceedings.cancel', $proceeding) }}"
                                  class="d-flex gap-2 align-items-end ms-auto">
                                @csrf
                                <div>
                                    <label class="form-label small mb-0">{{ __('ui.reason') }}</label>
                                    <input type="text" name="reason" class="form-control form-control-sm"
                                           style="width:18rem" placeholder="{{ __('cgpsc.proceedings.result.why_this_dpc_is_being_cancelled') }}">
                                </div>
                                <button class="btn btn-outline-danger">{{ __('cgpsc.proceedings.result.cancel_dpc') }}</button>
                            </form>
                        </div>
                        <div class="card-footer bg-transparent small text-muted">
                            {{ __('cgpsc.proceedings.result.approval_note') }}
                        </div>
                    </div>
                @endif

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-2">{{ __('ui.calculation_history') }}</h2>
                        <p class="text-muted small mb-2">
                            {{ __('cgpsc.proceedings.result.every_calculation_ever_run_for_this_dpc_nothing') }}
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>{{ __('ui.reference') }}</th><th>{{ __('cgpsc.proceedings.result.run_at') }}</th><th>{{ __('ui.by') }}</th><th>{{ __('ui.main') }}</th><th>{{ __('ui.waiting') }}</th><th></th></tr></thead>
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
                                                <span class="badge text-bg-success">{{ __('ui.approved') }}</span>
                                            @elseif ($proceeding->latest_calculation_run_id === $entry->id)
                                                <span class="badge text-bg-primary">{{ __('cgpsc.proceedings.result.current') }}</span>
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
                   href="{{ route('cgpsc.proceedings.show', $proceeding) }}">{{ __('cgpsc.proceedings.result.back_to_workspace') }}</a>
                <a class="btn btn-sm btn-outline-primary"
                   href="{{ route('cgpsc.proceedings.documents.index', $proceeding) }}">{{ __('cgpsc.proceedings.result.documents') }}</a>
                <a class="btn btn-sm btn-link"
                   href="{{ route('cgpsc.proceedings.history', $proceeding) }}">{{ __('ui.proceeding_history') }}</a>
            </div>
        </div>
    </div>
@endsection
