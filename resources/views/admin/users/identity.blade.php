{{-- CGPSC Super Admin → Users → Identity (2026-10-27). --}}
@extends('layouts.admin')

@section('title', __('identity.admin.title', ['name' => $user->name]))

@section('content')
    <h1 class="h4 mb-3">{{ __('identity.admin.title', ['name' => $user->name]) }}</h1>

    @include('partials.alerts')
    @error('identity')<div class="alert alert-danger small">{{ $message }}</div>@enderror

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body small">
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ __('identity.admin.status') }}</dt>
                <dd class="col-sm-9" id="identity-status">{{ $status }}@if ($user->identity_verified_at) — {{ $user->identity_verified_at->format('d-m-Y H:i') }}@endif</dd>
                <dt class="col-sm-3">{{ __('identity.field.name') }}</dt><dd class="col-sm-9">{{ $user->name }}</dd>
                <dt class="col-sm-3">{{ __('identity.field.date_of_birth') }}</dt><dd class="col-sm-9">{{ $user->date_of_birth?->format('d-m-Y') ?? '—' }}</dd>
                <dt class="col-sm-3">{{ __('identity.field.gender') }}</dt><dd class="col-sm-9">{{ $user->gender ? __('identity.gender.'.$user->gender) : '—' }}</dd>
                @if ($lockedUntil)
                    <dt class="col-sm-3"></dt><dd class="col-sm-9 text-danger">{{ __('identity.locked', ['time' => $lockedUntil->format('d-m-Y H:i')]) }}</dd>
                @endif
            </dl>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <form method="POST" action="{{ route('admin.users.identity.correct', $user) }}" enctype="multipart/form-data" class="card border-0 shadow-sm h-100">
                @csrf
                <div class="card-body small">
                    <h2 class="h6">{{ __('identity.admin.correct') }}</h2>
                    <p class="text-body-secondary">{{ __('identity.admin.correct_help') }}</p>
                    <label class="form-label" for="c_name">{{ __('identity.field.name') }}</label>
                    <input class="form-control form-control-sm mb-2" id="c_name" name="name" value="{{ old('name', $user->name) }}" required>
                    <label class="form-label" for="c_dob">{{ __('identity.field.date_of_birth') }}</label>
                    <input type="date" class="form-control form-control-sm mb-2" id="c_dob" name="date_of_birth" value="{{ old('date_of_birth', $user->date_of_birth?->toDateString()) }}" required>
                    <label class="form-label" for="c_gender">{{ __('identity.field.gender') }}</label>
                    <select class="form-select form-select-sm mb-2" id="c_gender" name="gender" required>
                        @foreach (['M', 'F'] as $g)
                            <option value="{{ $g }}" @selected(old('gender', $user->gender) === $g)>{{ __('identity.gender.'.$g) }}</option>
                        @endforeach
                    </select>
                    <label class="form-label" for="c_reason">{{ __('identity.admin.reason') }}</label>
                    <textarea class="form-control form-control-sm mb-2" id="c_reason" name="reason" rows="2" required></textarea>
                    <label class="form-label" for="c_doc">{{ __('identity.admin.document') }}</label>
                    <input type="file" class="form-control form-control-sm mb-2" id="c_doc" name="document" accept="application/pdf">
                    <button class="btn btn-primary btn-sm">{{ __('identity.admin.correct') }}</button>
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <form method="POST" action="{{ route('admin.users.identity.manual', $user) }}" enctype="multipart/form-data" class="card border-0 shadow-sm h-100">
                @csrf
                <div class="card-body small">
                    <h2 class="h6">{{ __('identity.admin.manual') }}</h2>
                    <p class="text-body-secondary">{{ __('identity.admin.manual_help') }}</p>
                    <label class="form-label" for="m_reason">{{ __('identity.admin.reason') }}</label>
                    <textarea class="form-control form-control-sm mb-2" id="m_reason" name="reason" rows="2" required></textarea>
                    <label class="form-label" for="m_doc">{{ __('identity.admin.document') }}</label>
                    <input type="file" class="form-control form-control-sm mb-2" id="m_doc" name="document" accept="application/pdf" required>
                    <button class="btn btn-warning btn-sm">{{ __('identity.admin.manual') }}</button>
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <form method="POST" action="{{ route('admin.users.identity.force', $user) }}" class="card border-0 shadow-sm h-100">
                @csrf
                <div class="card-body small">
                    <h2 class="h6">{{ __('identity.admin.force') }}</h2>
                    <p class="text-body-secondary">{{ __('identity.admin.force_help') }}</p>
                    <label class="form-label" for="f_reason">{{ __('identity.admin.reason') }}</label>
                    <textarea class="form-control form-control-sm mb-2" id="f_reason" name="reason" rows="2" required></textarea>
                    <button class="btn btn-outline-danger btn-sm">{{ __('identity.admin.force') }}</button>
                </div>
            </form>
        </div>
    </div>

    <h2 class="h6">{{ __('identity.admin.history') }}</h2>
    <div class="card border-0 shadow-sm mb-3"><div class="table-responsive">
        <table class="table table-sm small mb-0" id="identity-verifications">
            <thead><tr><th>{{ __('identity.admin.when') }}</th><th>{{ __('identity.admin.method') }}</th><th>{{ __('identity.admin.outcome') }}</th><th>{{ __('identity.admin.fields') }}</th><th>{{ __('identity.admin.by') }}</th><th>{{ __('identity.admin.reason') }}</th><th>{{ __('identity.admin.download') }}</th></tr></thead>
            <tbody>
                @forelse ($verifications as $row)
                    <tr>
                        <td>{{ $row->created_at->format('d-m-Y H:i') }}</td>
                        <td>{{ $row->method }}</td>
                        <td>{{ __('identity.outcome.'.$row->outcome) }}@if ($row->revoked_at)<div class="text-danger">{{ __('identity.admin.revoked') }} {{ $row->revoked_at->format('d-m-Y') }}: {{ $row->revoke_reason }}</div>@endif</td>
                        <td>{{ collect($row->mismatched_fields)->map(fn ($f) => __('identity.field.'.$f))->implode(', ') ?: '—' }}</td>
                        <td>{{ $row->decidedBy?->name ?? '—' }}</td>
                        <td>{{ $row->reason ?? '—' }}</td>
                        <td>@if ($row->document_path)<a href="{{ route('admin.users.identity.verification-document', [$user, $row]) }}">PDF</a>@else — @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">{{ __('identity.admin.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>

    <h2 class="h6">{{ __('identity.admin.corrections') }}</h2>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-sm small mb-0" id="identity-corrections">
            <thead><tr><th>{{ __('identity.admin.when') }}</th><th>{{ __('identity.admin.stage') }}</th><th>{{ __('identity.admin.fields') }}</th><th>{{ __('identity.admin.from_to') }}</th><th>{{ __('identity.admin.by') }}</th><th>{{ __('identity.admin.reason') }}</th><th>{{ __('identity.admin.download') }}</th></tr></thead>
            <tbody>
                @forelse ($corrections as $row)
                    <tr>
                        <td>{{ $row->created_at->format('d-m-Y H:i') }}</td>
                        <td>{{ __('identity.admin.stage_'.$row->stage) }}</td>
                        <td>{{ __('identity.field.'.$row->field) }}</td>
                        <td>{{ $row->old_value ?? '—' }} → {{ $row->new_value ?? '—' }}</td>
                        <td>{{ $row->correctedBy?->name ?? '—' }}</td>
                        <td>{{ $row->reason }}</td>
                        <td>@if ($row->document_path)<a href="{{ route('admin.users.identity.correction-document', [$user, $row]) }}">PDF</a>@else — @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">{{ __('identity.admin.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
@endsection
