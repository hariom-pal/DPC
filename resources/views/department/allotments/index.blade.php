{{-- Department Dashboard → Pre-DPC / DPC Allotment (2026-10-26). Every proposal of
     the department with the AO and AH it is allotted to; an unallotted one waits here
     (A4). The Department Admin allots and reassigns, never prepares or approves. --}}
@extends('layouts.app')
@section('title', __('allotments.list.'.$view.'.title'))
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">{{ __('allotments.list.'.$view.'.title') }}</h1>
            <p class="text-muted small mb-0">{{ __('allotments.list.'.$view.'.lead').' — '.$department->name }}</p>
        </div>
        @if ($view === 'pre-dpc')
            <a href="{{ route('department.allotments.pre-dpc.create') }}" class="btn btn-success btn-sm">{{ __('allotments.open') }}</a>
        @endif
    </div>

    @include('partials.alerts')

    @foreach ([[$kind, $rows]] as [$kind, $rows])
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle small" id="allotments-{{ $kind }}">
                    <thead><tr>
                        <th>{{ __('allotments.column.proposal') }}</th><th>{{ __('allotments.column.exercise') }}</th>
                        <th>{{ __('ui.status') }}</th><th>{{ __('allotments.column.ao') }}</th><th>{{ __('allotments.column.ah') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($rows as $proposal)
                            @php($current = $proposal->currentAllotment)
                            <tr data-proposal="{{ $proposal->uuid }}">
                                <td>{{ $kind === 'pre_dpc' ? $proposal->pre_dpc_number : $proposal->citation() }}</td>
                                <td>{{ $proposal->dpc_year }} · {{ $proposal->promotionToPost?->name ?? '—' }}</td>
                                <td>
                                    @if ($kind === 'pre_dpc')
                                        {{ __('allotments.stage.'.match ($proposal->status->value) { 'submitted' => 'with_ah', 'approved' => 'dpc_to_raise', default => 'with_ao' }) }}
                                    @else
                                        {{ $proposal->status->label() }}
                                    @endif
                                    @if (in_array($proposal->id, $issueIds, true))
                                        <span class="badge text-bg-danger ms-1" data-esign-issue>{{ __('esign.issue.badge') }}</span>
                                    @endif
                                </td>
                                @if ($current)
                                    <td>{{ $current->ao->name }}</td>
                                    <td>{{ $current->ah->name }}
                                        @if ($current?->held_at)
                                            <span class="badge text-bg-warning ms-1" data-allotment-held title="{{ $current->held_reason }}">{{ __('assignments.held_badge') }}</span>
                                        @endif
                                    </td>
                                @else
                                    <td colspan="2"><span class="badge text-bg-warning" data-allotment-pending>{{ __('allotments.pending') }}</span></td>
                                @endif
                                <td class="text-end text-nowrap">
                                    {{-- The Pre-DPC itself (2026-10-28): fill in or change a draft or a
                                         returned one; read any other. --}}
                                    @if ($kind === 'pre_dpc')
                                        @if ($proposal->status->isEditable())
                                            <a href="{{ route('pre-dpc.edit', $proposal) }}" class="btn btn-primary btn-sm" data-edit-pre-dpc>{{ __('allotments.edit_pre_dpc') }}</a>
                                        @else
                                            <a href="{{ route('pre-dpc.show', $proposal) }}" class="btn btn-outline-dark btn-sm">{{ __('allotments.view') }}</a>
                                        @endif
                                    @endif
                                    @if ($view !== 'closed' && ! $proposal->status->isTerminal())
                                        <a href="{{ route($kind === 'pre_dpc' ? 'department.allotments.pre-dpc.edit' : 'department.allotments.dpc.edit', $proposal) }}"
                                           class="btn btn-outline-primary btn-sm">{{ $current ? __('allotments.reassign') : __('allotments.allot') }}</a>
                                    @elseif ($view !== 'closed')
                                        <span class="badge text-bg-secondary" title="{{ __('allotments.errors.locked_after_signature') }}">{{ __('allotments.signed_badge') }}</span>
                                    @endif
                                    <a href="{{ route($kind === 'pre_dpc' ? 'department.allotments.pre-dpc.history' : 'department.allotments.dpc.history', $proposal) }}"
                                       class="btn btn-outline-secondary btn-sm">{{ __('allotments.history') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">{{ __('allotments.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
@endsection
