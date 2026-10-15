@extends('layouts.app')

@section('title', __('cgpsc.proceedings.index.online_dpc_proceedings'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            @include('partials.alerts')

            <h1 class="h4 mb-1">{{ __('cgpsc.proceedings.index.online_dpc_proceedings') }}</h1>
            <p class="text-muted small mb-3">
                {{ __('cgpsc.proceedings.index.dpcs_that_have_been_scheduled_and_are_ready_to_b') }}
            </p>

            @error('proceeding')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('ui.sn') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.dpc_id') }}</th>
                                    <th>{{ __('ui.post_from') }}</th><th>{{ __('ui.post_to') }}</th>
                                    <th>{{ __('cgpsc.proceedings.index.allotted_to') }}</th><th>{{ __('cgpsc.proceedings.index.dpc_date_amp_time') }}</th>
                                    <th>{{ __('ui.status') }}</th><th style="width:16rem">{{ __('ui.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($allotments as $index => $allotment)
                                @php
                                    $proposal = $allotment->proposal;
                                    $schedule = $schedules[$allotment->id] ?? null;
                                    $proceeding = $proposal ? ($proceedings[$proposal->id] ?? null) : null;
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $proposal?->department?->name }}</td>
                                    <td><code>{{ $proposal?->dpc_code ?? '—' }}</code></td>
                                    <td>{{ $proposal?->promotionFromPost?->name }}</td>
                                    <td>{{ $proposal?->promotionToPost?->name }}</td>
                                    <td>{{ $allotment->allottee?->name }}</td>
                                    <td>
                                        @if ($schedule)
                                            {{ $schedule->dpc_date->format('d/m/Y') }}
                                            {{ \Illuminate\Support\Carbon::parse($schedule->dpc_time)->format('h:i A') }}
                                        @else
                                            <span class="text-muted">{{ __('cgpsc.proceedings.index.not_scheduled') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($proceeding)
                                            <span class="badge {{ $proceeding->status->badgeClass() }}">
                                                {{ $proceeding->status->label() }}
                                            </span>
                                        @elseif ($schedule)
                                            <span class="badge text-bg-secondary">{{ __('cgpsc.proceedings.index.awaiting_proceeding') }}</span>
                                        @else
                                            <span class="badge text-bg-light text-dark">{{ __('cgpsc.proceedings.index.not_scheduled_2') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- `@can`, not `@if ($proceeding)`.
                                             Reading a proceeding belongs to the
                                             Chairman and to the Member who
                                             conducted it — and after a
                                             re-allotment those are not the same
                                             person as the current allottee, who
                                             is who this row is listed for. The
                                             plain @if offered that Member a
                                             button that could only 403. --}}
                                        @if ($proceeding && auth()->user()->can('view', $proceeding))
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="{{ route('cgpsc.proceedings.show', $proceeding) }}">{{ __('cgpsc.proceedings.index.open_workspace') }}</a>
                                            <a class="btn btn-sm btn-link"
                                               href="{{ route('cgpsc.proceedings.history', $proceeding) }}">{{ __('ui.history') }}</a>
                                        @elseif ($proceeding)
                                            <span class="text-muted small">
                                                {{-- The stored name, not the relation: it is already
                                                     on the row, and it is what the record says. --}}
{{ __('cgpsc.proceedings.index.conducted_by', ['actor' => $proceeding->conducted_by_name ?: __('cgpsc.proceedings.index.another_member')]) }}
                                            </span>
                                        @elseif ($schedule && $isAllottee($allotment))
                                            <form method="POST"
                                                  action="{{ route('cgpsc.proceedings.open', $proposal) }}"
                                                  class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-primary">{{ __('cgpsc.proceedings.index.open_proceeding') }}</button>
                                            </form>
                                        @else
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-muted text-center py-3">
                                    {{ __('cgpsc.proceedings.index.no_dpcs_are_awaiting_a_proceeding') }}
                                </td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
