@extends('layouts.admin')

@section('title', __('invitations.admin.title'))

@section('content')
    <div class="mb-3">
        <h1 class="h4 mb-1">{{ __('invitations.admin.title') }}</h1>
        <p class="text-muted small mb-0">{{ __('invitations.admin.lead') }}</p>
    </div>

    <div class="row g-3 mb-4">
        {{-- Send (2026-10-28): the department and its own e-mail, nothing else. --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100 form-section">
                <div class="card-header"><strong>{{ __('invitations.admin.send_title') }}</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.department-invitations.store') }}" class="row g-3">
                        @csrf
                        <div class="col-12">
                            <label for="department_id" class="form-label small fw-semibold">{{ __('ui.department') }}</label>
                            <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id" required>
                                <option value="">{{ __('ui.select') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" data-email="{{ $department->email }}" @selected((int) old('department_id') === $department->id)>{{ $department->name }}</option>
                                @endforeach
                            </select>
                            @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="email" class="form-label small fw-semibold">{{ __('invitations.admin.email') }}</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required>
                            <div class="form-text">{{ __('invitations.admin.email_help') }}</div>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success">{{ __('invitations.admin.send') }}</button>
                        </div>
                    </form>
                    <script src="@versioned('js/forms/department-invitation.js')" defer></script>
                </div>
            </div>
        </div>

        {{-- What the invited person goes through, so the Super Admin can explain it. --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><strong>{{ __('invitations.admin.next_title') }}</strong></div>
                <div class="card-body small">
                    <ol class="steps-list" id="invitation-steps">
                        <li>{{ __('invitations.admin.next.link') }}</li>
                        <li>{{ __('invitations.admin.next.otp') }}</li>
                        <li>{{ __('invitations.admin.next.digilocker') }}</li>
                        <li>{{ __('invitations.admin.next.password') }}</li>
                        <li>{{ __('invitations.admin.next.replaces') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>{{ __('invitations.admin.sent_title') }}</strong></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle small" id="department-invitations">
                <thead class="table-light"><tr>
                    <th>{{ __('ui.department') }}</th><th>{{ __('invitations.admin.email') }}</th><th>{{ __('ui.status') }}</th>
                    <th>{{ __('invitations.admin.expires') }}</th><th>{{ __('invitations.admin.delivery') }}</th><th class="text-end">{{ __('ui.actions') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($invitations as $invitation)
                        @php($state = $invitation->status())
                        <tr>
                            <td class="fw-semibold">{{ $invitation->department->name }}</td>
                            <td>{{ $invitation->email }}</td>
                            <td>
                                <span class="badge {{ ['pending' => 'text-bg-warning', 'accepted' => 'text-bg-success', 'revoked' => 'text-bg-secondary', 'expired' => 'text-bg-dark'][$state] ?? 'text-bg-light' }}">{{ __('invitations.status.'.$state) }}</span>
                                @if ($invitation->acceptedUser)<div class="text-muted mt-1">{{ $invitation->acceptedUser->name }}</div>@endif
                            </td>
                            <td>{{ $invitation->expires_at->format('d-m-Y H:i') }}</td>
                            <td>
                                @if ($invitation->delivery_error)
                                    <span class="text-danger">{{ __('invitations.admin.not_delivered') }}</span>
                                @elseif ($invitation->last_sent_at)
                                    {{ __('invitations.admin.sent_at', ['when' => $invitation->last_sent_at->format('d-m-Y H:i'), 'count' => $invitation->send_count]) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($invitation->accepted_at === null && $invitation->revoked_at === null)
                                    <form method="POST" action="{{ route('admin.department-invitations.resend', $invitation) }}" class="d-inline"
                                          data-confirm="{{ __('invitations.admin.confirm_resend') }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-primary btn-sm">{{ __('invitations.admin.resend') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.department-invitations.revoke', $invitation) }}" class="d-inline"
                                          data-confirm="{{ __('invitations.admin.confirm_revoke') }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('invitations.admin.revoke') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">{{ __('invitations.admin.none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $invitations->links() }}</div>
@endsection
