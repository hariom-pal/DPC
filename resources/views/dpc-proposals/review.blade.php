@extends('layouts.app')

@section('title', __('dpc.review.title', ['step' => $step, 'total' => count($steps)]))

{{-- The allotted AH of this proposal (2026-10-26). --}}
@php($isAH = app(\App\Domain\Allotment\Services\ProposalAllotmentService::class)->isAllotted(auth()->user(), $proposal, \App\Enums\Role::AdministrativeHead))
@php($current = $steps[$step - 1])

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            @include('partials.alerts')

            {{-- Administrative Head review walkthrough (PPT S21–S25). The AH sees
                 the same five sections the officer filled in, read-only, one screen
                 at a time, and approves ONCE at the end — the single-approval model
                 (architect decision): one signature, one transition, one timeline
                 entry, one audit event covering every section. --}}
            <h1 class="h5 mb-1">{{ __('dpc.review.approval_by_administrative_head') }}</h1>
            <p class="text-muted small mb-3">
{{ __('dpc.review.subheading', [
                    'from' => $proposal->promotionFromPost->name,
                    'to' => $proposal->promotionToPost->name,
                    'date' => $proposal->proposal_date->format('d-m-Y'),
                    'step' => $step,
                    'total' => count($steps),
                    'screen' => $current['screen'],
                ]) }}
            </p>

            <div class="progress mb-3" style="height:.5rem" role="progressbar"
                 aria-label="{{ __('dpc.review.review_progress') }}" aria-valuenow="{{ $step }}" aria-valuemin="1" aria-valuemax="{{ count($steps) }}">
                <div class="progress-bar bg-success" style="width: {{ (int) round($step / count($steps) * 100) }}%"></div>
            </div>

            <ul class="nav nav-pills nav-fill mb-3 small">
                @foreach ($steps as $index => $definition)
                    <li class="nav-item">
                        <a class="nav-link py-1 {{ $step === $index + 1 ? 'active' : '' }}"
                           href="{{ route('dpc-proposals.review', [$proposal, $index + 1]) }}">
                            {{ $index + 1 }}. {{ $definition['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>

            @include('dpc-proposals.sections.' . $current['partial'])

            {{-- Rule 1.1: what the department confirmed, shown before the AH signs. --}}
            @if ($step === count($steps))
                @include('dpc-proposals.sections.verification')
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            @if ($step > 1)
                                <a href="{{ route('dpc-proposals.review', [$proposal, $step - 1]) }}" class="btn btn-outline-secondary btn-sm">{{ __('dpc.review.previous') }}</a>
                            @endif
                            <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-link btn-sm">{{ __('dpc.review.view_full_proposal') }}</a>
                        </div>

                        <div>
                            @if ($step < count($steps))
                                <a href="{{ route('dpc-proposals.review', [$proposal, $step + 1]) }}" {{ __('dpc.review.next_step', ['label' => $steps[$step]['label']]) }}
                            @endif
                        </div>
                    </div>

                    @if ($step === count($steps))
                        <hr>
                        @if ($isAH && $proposal->status->value === 'submitted')
                            <p class="small mb-2">
{!! __('dpc.review.final_step_note', ['count' => count($steps)]) !!}
                            </p>
                            @error('workflow')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('esign.show', ['kind' => 'dpc-approve', 'uuid' => $proposal->uuid]) }}" class="btn btn-success" data-esign>{{ __('esign.button.approve_dpc') }}</a>
                            </div>

                            <form method="POST" action="{{ route('dpc-proposals.return', $proposal) }}" class="row g-2 mt-2">
                                @csrf
                                <div class="col-md-9">
                                    <input type="text" class="form-control form-control-sm @error('return_remarks') is-invalid @enderror"
                                           name="return_remarks" placeholder="{{ __('dpc.review.remarks_required_to_return_for_correction') }}">
                                    @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-3"><button class="btn btn-warning btn-sm w-100">{{ __('ui.return_for_correction') }}</button></div>
                            </form>
                        @else
                            <p class="text-muted small mb-0">
{{ __('dpc.review.no_action', ['status' => $proposal->status->label()]) }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
