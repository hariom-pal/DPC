{{-- Department Dashboard → User Management (2026-10-29). The officers registered
     in the department, and Release for a transferred AO/AH: a released officer may
     be chosen by another department. Officers are added from the AO/AH picker. --}}
@extends('layouts.app')
@section('title', __('department.users.title'))
@section('content')
    <div class="mb-3">
        <h1 class="h4 mb-1">{{ __('department.users.title') }}</h1>
        <p class="text-muted small mb-0">{{ __('department.users.list_lead', ['department' => $department->name]) }}</p>
    </div>

    @include('partials.alerts')
    @error('release')<div class="alert alert-danger">{{ $message }}</div>@enderror

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle small" id="department-users">
                <thead><tr>
                    <th>{{ __('ui.name') }}</th><th>{{ __('ui.email') }}</th><th>{{ __('ui.mobile') }}</th>
                    <th>{{ __('department.users.role') }}</th><th>{{ __('department.users.since') }}</th>
                    <th>{{ __('department.users.allotted') }}</th>
                    <th class="text-end">{{ __('ui.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($officers as $officer)
                        @php($assignment = $officer->activeAssignments->first())
                        <tr data-officer="{{ $officer->uuid }}">
                            <td>{{ $officer->name }}@if ($officer->designation)<div class="text-muted">{{ $officer->designation->name }}</div>@endif</td>
                            <td>{{ $officer->email }}</td>
                            <td>{{ $officer->mobile ?? '—' }}</td>
                            <td>{{ $assignment?->role?->label() ?? '—' }}</td>
                            <td>{{ $assignment?->effective_from?->format('d-m-Y') ?? '—' }}</td>
                            <td>{{ __('department.users.allotted_counts', ['ao' => $counts['ao'][$officer->id] ?? 0, 'ah' => $counts['ah'][$officer->id] ?? 0]) }}</td>
                            <td class="text-end">
                                @if (in_array($officer->id, $busy, true))
                                    <span class="text-muted">{{ __('department.users.release.busy') }}</span>
                                @else
                                    <form method="POST" action="{{ route('department.users.release', $officer) }}" class="d-flex gap-1 justify-content-end"
                                          data-confirm="{{ __('department.users.release.confirm') }}">
                                        @csrf
                                        {{-- A reason from the list, and the eSign (MoM of 2026-10-29). --}}
                                        <label class="visually-hidden" for="reason-{{ $officer->id }}">{{ __('department.users.release.reason') }}</label>
                                        <select class="form-select form-select-sm" id="reason-{{ $officer->id }}" name="reason" required style="min-width: 14rem">
                                            <option value="">{{ __('department.users.release.reason') }}</option>
                                            @foreach (\App\Enums\OfficerChangeReason::cases() as $reason)
                                                <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                            @endforeach
                                        </select>
                                        <div class="form-check form-check-inline small text-nowrap align-self-center me-0">
                                            <input class="form-check-input" type="checkbox" name="consent" value="1" id="consent-{{ $officer->id }}" required>
                                            <label class="form-check-label" for="consent-{{ $officer->id }}">{{ __('department.users.release.esign') }}</label>
                                        </div>
                                        <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('department.users.release.button') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('department.users.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
