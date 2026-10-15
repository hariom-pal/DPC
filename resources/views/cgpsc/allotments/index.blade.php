@extends('layouts.app')

@section('title', __('cgpsc.allotments.index.allotment_of_dpc'))

@php($isChairman = auth()->user()->hasRole(\App\Enums\Role::Chairman->value))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.allotments.index.allotment_of_dpc') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('cgpsc.allotments.index.proposals_approved_by_their_department_and_forwa') }}
            </p>

            @error('allotment')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            {{-- S28 — allotment --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6">{{ __('cgpsc.allotments.index.proposals_awaiting_action') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ui.sn') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.dpc_p_no') }}</th><th>{{ __('ui.proposal_date') }}</th>
                                    <th>{{ __('ui.post_from') }}</th><th>{{ __('ui.post_to') }}</th><th style="width:26rem">{{ __('cgpsc.allotments.index.dpc_allotted_to') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($proposals as $index => $proposal)
                                    @php($current = $currentByProposal[$proposal->id] ?? null)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $proposal->department->name }}</td>
                                        <td class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</td>
                                        <td>{{ $proposal->proposal_date->format('d/m/Y') }}</td>
                                        <td>{{ $proposal->promotionFromPost->name }}</td>
                                        <td>{{ $proposal->promotionToPost->name }}</td>
                                        <td>
                                            @if ($current === null)
                                                @if ($isChairman)
                                                    <form method="POST" action="{{ route('cgpsc.allotments.store', $proposal) }}" class="row g-1">
                                                        @csrf
                                                        {{-- **This DPC's own committee, not every Commission
                                                             account** (business instruction, 2026-09-06).
                                                             Only seats carrying a portal account are offered:
                                                             the allottee has to sign in, accept and schedule.

                                                             The "suggested DPC date" that used to sit beside
                                                             this is gone — two dates on one row, one required
                                                             and one not, with nothing on screen saying how
                                                             they differed, and only the deadline governed
                                                             anything. The allotment's own date is today, by
                                                             construction. --}}
                                                        <div class="col-8">
                                                            <label class="form-label small mb-0">{{ __('cgpsc.allotments.index.allot_to') }}</label>
                                                            <select name="allotted_to" class="form-select form-select-sm" required>
                                                                <option value="">{{ __('cgpsc.allotments.index.allot_to') }}</option>
                                                                @foreach ($allotteesByProposal[$proposal->id] ?? [] as $seat)
                                                                    <option value="{{ $seat->user_id }}">{{ $seat->name }} — {{ $seat->capacity->label() }}</option>
                                                                @endforeach
                                                            </select>
                                                            @if (($allotteesByProposal[$proposal->id] ?? collect())->isEmpty())
                                                                <div class="form-text text-danger">{{ __('cgpsc.allotments.index.no_allottable_member') }}</div>
                                                            @endif
                                                        </div>
                                                        <div class="col-4 align-self-end"><button class="btn btn-primary btn-sm w-100">{{ __('cgpsc.allotments.index.allot') }}</button></div>
                                                        {{-- Item 9 (2026-08-26): the date the DPC must be
                                                             concluded by. Required, because the rule that an
                                                             unconcluded DPC returns to the Chairman cannot
                                                             operate on an allotment that has no deadline. --}}
                                                        {{-- Business clarification of 2026-09-05: the CGPSC
                                                             Chairman decides the allottee, the DPC Chairman
                                                             and the deadline in one act. The list is this
                                                             DPC's own committee, and it includes the CGPSC
                                                             Chairman's own seat — so presiding personally is
                                                             this control, not a separate one. --}}
                                                        <div class="col-12">
                                                            <label class="form-label small mb-0">{{ __('cgpsc.allotments.index.dpc_chairman') }}</label>
                                                            <select name="dpc_chairman_member_id" class="form-select form-select-sm" required>
                                                                <option value="">{{ __('cgpsc.allotments.index.dpc_chairman_choose') }}</option>
                                                                @foreach ($committeeByProposal[$proposal->id] ?? [] as $seat)
                                                                    <option value="{{ $seat->id }}" @selected($seat->isDpcChairman())>
                                                                        {{ $seat->name }} — {{ $seat->capacity->label() }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                            @if (($committeeByProposal[$proposal->id] ?? collect())->isEmpty())
                                                                <div class="form-text text-danger">{{ __('cgpsc.allotments.index.dpc_chairman_no_committee') }}</div>
                                                            @endif
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label small mb-0">{{ __('cgpsc.allotments.index.complete_by') }}</label>
                                                            <input type="date" name="complete_by" class="form-control form-control-sm"
                                                                   title="{{ __('cgpsc.allotments.index.complete_by_hint') }}" required>
                                                        </div>
                                                    </form>
                                                @else
                                                    <span class="text-muted small">{{ __('cgpsc.allotments.index.not_yet_allotted') }}</span>
                                                @endif
                                            @else
                                                @php($superseded = $supersededByCurrent[$current->id] ?? null)
                                                <strong>{{ $current->allottee->name }}</strong>
                                                @if ($superseded !== null)
                                                    {!! __('cgpsc.allotments.index.was', ['name' => e($superseded->allottee->name)]) !!}
                                                @endif
                                                <span class="badge {{ $current->status->badgeClass() }}">{{ $current->status->label() }}</span>
                                                <div class="small text-muted">
{{ __('cgpsc.allotments.index.allotted_by', [
                                                    'date' => $current->allotted_at->format('d/m/Y'),
                                                    'actor' => $current->allottedBy?->name ?? '—',
                                                ]) }}
                                                    {{-- Who presides, stated beside who executes: the two
                                                         may be the same person or different people, and both
                                                         are valid (business clarification, 2026-09-05). --}}
                                                    @php($presiding = ($committeeByProposal[$proposal->id] ?? collect())->first(fn ($seat) => $seat->isDpcChairman()))
                                                    <div>
                                                        {{ __('cgpsc.allotments.index.dpc_chairman') }}:
                                                        <strong>{{ $presiding?->name ?? __('cgpsc.allotments.index.dpc_chairman_none') }}</strong>
                                                    </div>
                                                    @if ($current->complete_by)
                                                        <div>
                                                            {{ __('cgpsc.allotments.index.complete_by') }}:
                                                            <strong>{{ $current->complete_by->format('d/m/Y') }}</strong>
                                                            {{-- Said on the screen rather than only swept by the
                                                                 scheduled command, so the Chairman can see an
                                                                 allotment running over before it is taken back. --}}
                                                            @if ($current->complete_by->isPast())
                                                                <span class="badge text-bg-danger">{{ __('cgpsc.allotments.index.overdue') }}</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>

                                                @if ($isChairman)
                                                    <details class="mt-1">
                                                        <summary class="small text-primary" style="cursor:pointer">{{ __('cgpsc.allotments.index.reallot') }}</summary>
                                                        <form method="POST" action="{{ route('cgpsc.allotments.reallot', $proposal) }}" class="row g-1 mt-1">
                                                            @csrf
                                                            <div class="col-8">
                                                                <select name="allotted_to" class="form-select form-select-sm" required>
                                                                    <option value="">{{ __('cgpsc.allotments.index.re_allot_to') }}</option>
                                                                    @foreach ($allotteesByProposal[$proposal->id] ?? [] as $seat)
                                                                        @if ($seat->user_id !== $current->allotted_to)
                                                                            <option value="{{ $seat->user_id }}">{{ $seat->name }} — {{ $seat->capacity->label() }}</option>
                                                                        @endif
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            <div class="col-4"><button class="btn btn-outline-warning btn-sm w-100">{{ __('cgpsc.allotments.index.re_allot') }}</button></div>
                                                            <div class="col-12">
                                                                <label class="form-label small mb-0">{{ __('cgpsc.allotments.index.dpc_chairman') }}</label>
                                                                <select name="dpc_chairman_member_id" class="form-select form-select-sm" required>
                                                                    <option value="">{{ __('cgpsc.allotments.index.dpc_chairman_choose') }}</option>
                                                                    @foreach ($committeeByProposal[$proposal->id] ?? [] as $seat)
                                                                        <option value="{{ $seat->id }}" @selected($seat->isDpcChairman())>
                                                                            {{ $seat->name }} — {{ $seat->capacity->label() }}
                                                                        </option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="form-label small mb-0">{{ __('cgpsc.allotments.index.complete_by') }}</label>
                                                                <input type="date" name="complete_by" class="form-control form-control-sm" required>
                                                            </div>
                                                            <div class="col-12">
                                                                <input type="text" name="reallotment_reason" class="form-control form-control-sm"
                                                                       placeholder="{{ __('cgpsc.allotments.index.reason_for_re_allotment_required') }}" required>
                                                            </div>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">
                                        {{ __('cgpsc.allotments.index.no_proposal_has_been_forwarded_to_the_commission') }}
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- S28 — DPC Allotment Report --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6">{{ __('cgpsc.allotments.index.dpc_allotment_report') }}</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ui.sn') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.dpc_p_no') }}</th><th>{{ __('ui.proposal_date') }}</th>
                                    <th>{{ __('ui.post_from') }}</th><th>{{ __('ui.post_to') }}</th><th>{{ __('cgpsc.allotments.index.dpc_allotted_to') }}</th>
                                    <th>{{ __('ui.status') }}</th><th>{{ __('cgpsc.allotments.index.complete_by') }}</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php($reported = 0)
                                @foreach ($proposals as $proposal)
                                    @php($current = $currentByProposal[$proposal->id] ?? null)
                                    @continue($current === null)
                                    @php($reported++)
                                    <tr>
                                        <td>{{ $reported }}</td>
                                        <td>{{ $proposal->department->name }}</td>
                                        <td class="font-monospace">{{ $proposal->dpc_code ?? '—' }}</td>
                                        <td>{{ $proposal->proposal_date->format('d/m/Y') }}</td>
                                        <td>{{ $proposal->promotionFromPost->name }}</td>
                                        <td>{{ $proposal->promotionToPost->name }}</td>
                                        @php($superseded = $supersededByCurrent[$current->id] ?? null)
                                        <td>
                                            {{ $current->allottee->name }}
                                            @if ($superseded !== null)
                                                <br><s class="text-muted small">{{ $superseded->allottee->name }}</s>
                                            @endif
                                        </td>
                                        <td><span class="badge {{ $current->status->badgeClass() }}">{{ $current->status->label() }}</span></td>
                                        <td>{{ $current->complete_by?->format('d/m/Y') ?? '—' }}</td>
                                        <td>
                                            <a href="{{ route('cgpsc.allotments.history', $proposal) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.history') }}</a>
                                        </td>
                                    </tr>
                                @endforeach

                                @if ($reported === 0)
                                    <tr><td colspan="10" class="text-center text-muted py-4">{{ __('cgpsc.allotments.index.no_dpc_has_been_allotted_yet') }}</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
