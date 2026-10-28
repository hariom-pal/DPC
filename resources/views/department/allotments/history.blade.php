{{-- Every allotment of a proposal, newest first (A5, 2026-10-26). --}}
@extends('layouts.app')
@section('title', __('allotments.history_title', ['proposal' => $label]))
@section('content')
    <h1 class="h4 mb-3">{{ __('allotments.history_title', ['proposal' => $label]) }}</h1>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle small" id="allotment-history">
                <thead><tr>
                    <th>{{ __('allotments.column.ao') }}</th><th>{{ __('allotments.column.ah') }}</th>
                    <th>{{ __('allotments.column.allotted') }}</th><th>{{ __('allotments.column.ended') }}</th><th>{{ __('allotments.field.reason') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->ao->name }}</td>
                            <td>{{ $row->ah->name }}</td>
                            <td>{{ $row->allotted_at->format('d-m-Y H:i') }}<div class="text-muted">{{ $row->allottedBy?->name ?? __('allotments.inherited') }}</div></td>
                            <td>
                                @if ($row->ended_at)
                                    {{ $row->ended_at->format('d-m-Y H:i') }}<div class="text-muted">{{ $row->endedBy?->name }}</div>
                                @else
                                    <span class="badge text-bg-success">{{ __('allotments.current') }}</span>
                                @endif
                            </td>
                            <td>{{ $row->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">{{ __('allotments.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <a href="{{ route('department.allotments.index') }}" class="btn btn-outline-secondary btn-sm mt-3">{{ __('allotments.back') }}</a>
@endsection
