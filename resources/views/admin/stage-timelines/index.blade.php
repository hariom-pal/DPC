@extends('layouts.admin')
@section('title', __('admin.stage_timelines.title'))
@section('content')
    {{-- MoM of 16 November 2026, point 6: the days each stage of a DPC is allowed. --}}
    <h1 class="h4 mb-1">{{ __('admin.stage_timelines.title') }}</h1>
    <p class="text-muted small">{{ __('admin.stage_timelines.intro') }}</p>

    @if (session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.stage-timelines.update') }}" class="card border-0 shadow-sm" id="stage-timelines">
        @csrf
        @method('PUT')
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('admin.stage_timelines.stage') }}</th>
                        <th>{{ __('admin.stage_timelines.action') }}</th>
                        <th style="width: 12rem">{{ __('admin.stage_timelines.days') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stages as $stage)
                        <tr>
                            <td class="fw-semibold">{{ $stage->label() }}</td>
                            <td class="small">{{ $stage->action() }}</td>
                            <td>
                                <input type="number" min="1" max="365" class="form-control form-control-sm @error('days.'.$stage->value) is-invalid @enderror"
                                       name="days[{{ $stage->value }}]" value="{{ old('days.'.$stage->value, $timelines->get($stage->value)?->days) }}" data-stage-days="{{ $stage->value }}">
                                @error('days.'.$stage->value)<div class="invalid-feedback">{{ $message }}</div>@enderror
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
