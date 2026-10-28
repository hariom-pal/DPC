@extends('layouts.admin')
@section('title', __('assignments.admin_title'))
@section('content')
    <h1 class="h4 mb-3">{{ __('assignments.admin_title') }}</h1>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 small align-middle" id="assignment-requests">
                <thead><tr><th>{{ __('ui.type') }}</th><th>{{ __('assignments.field.officer_name') }}</th><th>{{ __('assignments.field.from') }}</th><th>{{ __('assignments.field.to') }}</th><th>{{ __('ui.role') }}</th><th>{{ __('assignments.field.order_number') }}</th><th>{{ __('assignments.field.effective_from') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('assignments.field.decided') }}</th></tr></thead>
                <tbody>
                    @forelse ($requests as $item)
                        <tr data-request="{{ $item->uuid }}">
                            <td>{{ __('assignments.type.'.$item->type) }}</td>
                            <td>{{ $item->user?->name }}<div class="text-muted">{{ $item->user?->email }}</div></td>
                            <td>{{ $item->fromDepartment?->name ?? '—' }}</td>
                            <td>{{ $item->toDepartment?->name }}<div class="text-muted">{{ __('assignments.field.requested_by') }}: {{ $item->requestedBy?->name ?? '—' }}</div></td>
                            <td>{{ $item->role->label() }}</td>
                            <td>
                                {{ $item->order_number }} · {{ $item->order_date->format('d/m/Y') }}
                                @if ($item->document_path)
                                    <div><a href="{{ route('admin.assignment-requests.document', $item) }}">{{ __('assignments.field.order') }}</a></div>
                                @endif
                                @if ($item->reason)<div class="text-muted">{{ $item->reason }}</div>@endif
                            </td>
                            <td>{{ $item->effective_from->format('d/m/Y') }}@if ($item->effective_to) – {{ $item->effective_to->format('d/m/Y') }}@endif</td>
                            <td>
                                {{ __('assignments.status.'.$item->status) }}
                                @if ($item->decidedBy)<div class="text-muted">{{ $item->decidedBy->name }}, {{ $item->decided_at?->format('d/m/Y H:i') }}</div>@endif
                                @if ($item->decision_reason)<div class="text-muted">{{ $item->decision_reason }}</div>@endif
                            </td>
                            <td class="text-end">
                                @if ($item->status === \App\Domain\UserManagement\Models\AssignmentRequest::STATUS_SUBMITTED)
                                    <form method="POST" action="{{ route('admin.assignment-requests.decide', $item) }}" class="d-flex flex-column gap-1 align-items-end">
                                        @csrf
                                        <input class="form-control form-control-sm" name="reason" maxlength="1000" placeholder="{{ __('assignments.field.reason') }}" aria-label="{{ __('assignments.field.reason') }}">
                                        <div class="d-flex gap-1">
                                            <button name="decision" value="approve" class="btn btn-success btn-sm">{{ __('assignments.approve') }}</button>
                                            <button name="decision" value="reject" class="btn btn-outline-danger btn-sm">{{ __('assignments.reject') }}</button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-muted">{{ __('assignments.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $requests->links() }}</div>
@endsection
