{{-- MoM of 16 November 2026, points 2 and 3: each committee member's decision on the
     DPC — agreed, or returned to the AO naming the sections to correct, each with its
     remark — and every decision recorded so far. Expects: $proposal. --}}
@php
    $decisions = \App\Domain\Dpc\Models\DpcCommitteeDecision::query()->where('dpc_proposal_id', $proposal->id)->latest('decided_at')->latest('id')->get();
    $sectionKeys = \App\Domain\Dpc\Services\CommitteeDecisionService::SECTIONS;
@endphp
<div class="card border-0 shadow-sm mb-3" id="committee-decision">
    <div class="card-body">
        <h2 class="h6">{{ __('dpc.committee_decision.heading') }}</h2>
        @can('committeeDecide', $proposal)
            <form method="POST" action="{{ route('sitting.decision', $proposal) }}" class="border rounded p-2 mb-3">
                @csrf
                @error('decision')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                {{-- Agree only once every candidate carries this member's OK (2026-11-18). --}}
                @php($okService = app(\App\Domain\Proceeding\Services\SittingMeetingService::class))
                @php($awaitingOk = in_array(auth()->id(), $okService->requiredMemberIds($proposal), true) ? count($okService->candidatesAwaitingOk($proposal, (int) auth()->id())) : 0)
                @if ($awaitingOk > 0)
                    <div class="alert alert-warning py-2 small" id="ok-before-agree">{{ __('dpc.committee_decision.ok_first', ['count' => $awaitingOk]) }}</div>
                @endif
                @foreach (['agreed', 'returned'] as $choice)
                    <label class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="decision" value="{{ $choice }}" required @checked(old('decision') === $choice)>
                        {{ __('dpc.committee_decision.choices.'.$choice) }}
                    </label>
                @endforeach
                <div class="mt-2" data-return-sections>
                    <p class="small text-muted mb-1">{{ __('dpc.committee_decision.sections_help') }}</p>
                    @foreach ($sectionKeys as $section)
                        <div class="row g-2 align-items-start mb-1">
                            <div class="col-md-4">
                                <label class="form-check small">
                                    <input class="form-check-input" type="checkbox" name="sections[]" value="{{ $section }}" @checked(in_array($section, (array) old('sections', []), true))>
                                    {{ __('dpc.committee_decision.sections.'.$section) }}
                                </label>
                            </div>
                            <div class="col-md-8">
                                <input type="text" class="form-control form-control-sm @error('section_remarks.'.$section) is-invalid @enderror" name="section_remarks[{{ $section }}]" maxlength="2000"
                                       value="{{ old('section_remarks.'.$section) }}" placeholder="{{ __('dpc.committee_decision.remark_placeholder') }}">
                                @error('section_remarks.'.$section)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
                <label class="form-label small mt-2" for="decision-remarks">{{ __('dpc.committee_decision.remarks') }}</label>
                <textarea class="form-control form-control-sm" id="decision-remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                <button class="btn btn-primary btn-sm mt-2">{{ __('dpc.committee_decision.submit') }}</button>
            </form>
        @endcan

        @if ($decisions->isEmpty())
            <p class="small text-muted mb-0">{{ __('dpc.committee_decision.none') }}</p>
        @else
            <ul class="list-unstyled small mb-0">
                @foreach ($decisions as $decision)
                    <li class="mb-2" data-committee-decision="{{ $decision->decision }}">
                        <span class="badge {{ $decision->decision === 'agreed' ? 'text-bg-success' : 'text-bg-warning' }}">{{ __('dpc.committee_decision.choices.'.$decision->decision) }}</span>
                        <strong>{{ $decision->member_name }}</strong> — {{ $decision->decided_at->format('d-m-Y H:i') }}
                        @if ($decision->remarks)<div>{{ $decision->remarks }}</div>@endif
                        @foreach ((array) $decision->sections as $section => $remark)
                            <div>{{ __('dpc.committee_decision.sections.'.$section) }}: {{ $remark }}</div>
                        @endforeach
                        @if ($decision->resubmitted_at)<div class="text-muted">{{ __('dpc.committee_decision.resubmitted_on', ['date' => $decision->resubmitted_at->format('d-m-Y H:i')]) }}</div>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
