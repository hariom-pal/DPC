@extends('layouts.admin')
@section('title', __('admin.stage_timelines.title'))
@section('content')
    {{-- MoM of 16 November 2026, point 6, and 2026-11-17: each stage's days, what
         happens once they pass, and how often and how the reminders go. --}}
    <h1 class="h4 mb-1">{{ __('admin.stage_timelines.title') }}</h1>
    <p class="text-muted small">{{ __('admin.stage_timelines.intro') }}</p>

    @if (session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.stage-timelines.update') }}" class="card border-0 shadow-sm" id="stage-timelines">
        @csrf
        @method('PUT')
        <input type="hidden" name="email[_]" value="0">
        <input type="hidden" name="sms[_]" value="0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('admin.stage_timelines.stage') }}</th>
                        <th style="width: 8rem">{{ __('admin.stage_timelines.days') }}</th>
                        <th style="width: 16rem">{{ __('admin.stage_timelines.on_expiry') }}</th>
                        <th style="width: 9rem">{{ __('admin.stage_timelines.every') }}</th>
                        <th>{{ __('admin.stage_timelines.channels') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stages as $stage)
                        @php
                            $timeline = $timelines[$stage->value];
                        @endphp
                        <tr data-stage-row="{{ $stage->value }}">
                            <td>
                                <div class="fw-semibold">{{ $stage->label() }}</div>
                                <div class="small text-muted">{{ $stage->action() }}</div>
                            </td>
                            <td>
                                <input type="number" min="1" max="365" class="form-control form-control-sm @error('days.'.$stage->value) is-invalid @enderror"
                                       name="days[{{ $stage->value }}]" value="{{ old('days.'.$stage->value, $timeline->days) }}" data-stage-days="{{ $stage->value }}">
                                @error('days.'.$stage->value)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                            <td>
                                <select class="form-select form-select-sm @error('on_expiry.'.$stage->value) is-invalid @enderror" name="on_expiry[{{ $stage->value }}]" data-stage-expiry="{{ $stage->value }}">
                                    @foreach ($stage->expiryActions() as $action)
                                        <option value="{{ $action }}" @selected(old('on_expiry.'.$stage->value, $timeline->on_expiry) === $action)>{{ __('admin.stage_timelines.actions.'.$action, ['next' => $stage->next()?->label() ?? '—']) }}</option>
                                    @endforeach
                                </select>
                                @error('on_expiry.'.$stage->value)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" min="1" max="30" class="form-control @error('every.'.$stage->value) is-invalid @enderror" name="every[{{ $stage->value }}]" value="{{ old('every.'.$stage->value, $timeline->reminder_every_days) }}">
                                    <span class="input-group-text">{{ __('admin.stage_timelines.days_unit') }}</span>
                                </div>
                            </td>
                            <td class="small">
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="email[{{ $stage->value }}]" value="1" @checked(old('email.'.$stage->value, $timeline->remind_email))> {{ __('admin.stage_timelines.email') }}
                                </label>
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="sms[{{ $stage->value }}]" value="1" @checked(old('sms.'.$stage->value, $timeline->remind_sms))> {{ __('admin.stage_timelines.sms') }}
                                </label>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-body border-top">
            <p class="small text-muted">{{ __('admin.stage_timelines.help') }}</p>
            <button class="btn btn-success btn-sm">{{ __('ui.save') }}</button>
        </div>
    </form>
@endsection
