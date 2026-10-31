@extends('layouts.admin')

@section('title', __('admin.users.assignments.role_assignments'))

@section('content')
    {{-- Role Assignments (change items 1 and 2). The officer's postings over
         time: one live, any number closed, none ever deleted. --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">{{ __('admin.users.assignments.role_assignments') }}</h1>
            <div class="text-muted small">{{ $user->name }} — {{ $user->email ?? $user->mobile }}</div>
        </div>
        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('admin.users.assignments.back_to_users') }}</a>
    </div>

    <div class="alert alert-secondary small">
        {{ __('admin.users.assignments.hint_history') }}
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>{{ __('admin.users.assignments.assignment_history') }}</strong></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.department') }}</th><th>{{ __('ui.role') }}</th><th>{{ __('ui.effective_from') }}</th><th>{{ __('ui.effective_to') }}</th>
                        <th>{{ __('ui.active') }}</th><th>{{ __('admin.users.assignments.remarks') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($assignments as $assignment)
                        <tr class="{{ $assignment->isActive() ? 'table-success' : '' }}">
                            <td>{{ $assignment->department?->name ?? '—' }}</td>
                            <td>{{ $assignment->role->label() }}</td>
                            <td>{{ $assignment->effective_from->format('d-m-Y') }}</td>
                            <td>{{ $assignment->effective_to?->format('d-m-Y') ?? '—' }}</td>
                            <td>
                                @if ($assignment->isActive())
                                    <span class="badge text-bg-success">{{ __('ui.active') }}</span>
                                @else
                                    <span class="badge text-bg-secondary">{{ __('admin.users.assignments.closed') }}</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $assignment->remarks ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                {{ __('admin.users.assignments.this_account_holds_no_departmental_posting_if_it') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <strong>{{ $user->activeAssignment === null ? __('ui.screen.record_a_posting') : __('ui.screen.transfer') }}</strong>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.assignments.store', $user) }}">
                @csrf
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="department_id">{{ __('ui.department') }}</label>
                        <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="role">{{ __('ui.role') }}</label>
                        <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                            <option value="">{{ __('ui.select') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label" for="effective_from">{{ __('ui.effective_from') }}</label>
                        <input type="date" class="form-control @error('effective_from') is-invalid @enderror"
                               id="effective_from" name="effective_from" value="{{ old('effective_from') }}">
                        @error('effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">{{ __('admin.users.assignments.hint_effective_from') }}</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="remarks">{{ __('admin.users.assignments.remarks') }}</label>
                        <input type="text" maxlength="255" class="form-control @error('remarks') is-invalid @enderror"
                               id="remarks" name="remarks" value="{{ old('remarks') }}" placeholder="{{ __('admin.users.assignments.transfer_order_number') }}">
                        @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-success">
                    {{ $user->activeAssignment === null ? __('ui.screen.record_posting') : __('ui.screen.transfer') }}
                </button>
            </form>

            @if ($user->activeAssignment !== null)
                <hr>
                <form method="POST" action="{{ route('admin.users.assignments.release', $user) }}"
                      data-confirm="{{ __('admin.users.assignments.close_this_officer_s_current_posting_the_account') }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger btn-sm">{{ __('admin.users.assignments.release_from_current_posting') }}</button>
                    <span class="text-muted small ms-2">
                        {{ __('admin.users.assignments.use_this_when_a_posting_ends_and_the_next_one_is') }}
                    </span>
                </form>
            @endif
        </div>
    </div>
@endsection
