@extends('layouts.app')

@section('title', 'Approve DPC Proposal — Step ' . $step . ' of ' . count($steps))

@php($isAH = auth()->user()->hasRole(\App\Enums\Role::AdministrativeHead->value))
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
            <h1 class="h5 mb-1">Approval by Administrative Head</h1>
            <p class="text-muted small mb-3">
                {{ $proposal->postFromCadre->name }} → {{ $proposal->postToCadre->name }}
                · Proposal dated {{ $proposal->proposal_date->format('d-m-Y') }}
                · Step {{ $step }} of {{ count($steps) }} ({{ $current['screen'] }})
            </p>

            <div class="progress mb-3" style="height:.5rem" role="progressbar"
                 aria-label="Review progress" aria-valuenow="{{ $step }}" aria-valuemin="1" aria-valuemax="{{ count($steps) }}">
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

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            @if ($step > 1)
                                <a href="{{ route('dpc-proposals.review', [$proposal, $step - 1]) }}" class="btn btn-outline-secondary btn-sm">← Previous</a>
                            @endif
                            <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-link btn-sm">View full proposal</a>
                        </div>

                        <div>
                            @if ($step < count($steps))
                                <a href="{{ route('dpc-proposals.review', [$proposal, $step + 1]) }}" class="btn btn-primary btn-sm">Next: {{ $steps[$step]['label'] }} →</a>
                            @endif
                        </div>
                    </div>

                    @if ($step === count($steps))
                        <hr>
                        @if ($isAH && $proposal->status->value === 'submitted')
                            <p class="small mb-2">
                                You have reviewed all {{ count($steps) }} sections. Approving digitally signs the
                                <strong>entire</strong> proposal — every section above — and forwards it to the
                                Chhattisgarh Public Service Commission (S19). This cannot be undone.
                            </p>
                            @error('workflow')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('dpc-proposals.approve', $proposal) }}"
                                      onsubmit="return confirm('Approve, digitally sign, and forward this proposal to CGPSC?');">
                                    @csrf<button class="btn btn-success">Approve, Sign &amp; Forward to CGPSC</button>
                                </form>
                            </div>

                            <form method="POST" action="{{ route('dpc-proposals.return', $proposal) }}" class="row g-2 mt-2">
                                @csrf
                                <div class="col-md-9">
                                    <input type="text" class="form-control form-control-sm @error('return_remarks') is-invalid @enderror"
                                           name="return_remarks" placeholder="Remarks (required to return for correction)">
                                    @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-3"><button class="btn btn-warning btn-sm w-100">Return for Correction</button></div>
                            </form>
                        @else
                            <p class="text-muted small mb-0">
                                This proposal is {{ $proposal->status->label() }} — no approval action is available.
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
