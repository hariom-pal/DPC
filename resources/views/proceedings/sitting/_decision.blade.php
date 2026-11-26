{{-- The DPC Chairman's Accept or Reject of the DPC, with a remark (2026-11-18), and
     every decision recorded so far. Expects: $proposal. --}}
@php
    $decisions = \App\Domain\Dpc\Models\DpcCommitteeDecision::query()->where('dpc_proposal_id', $proposal->id)->latest('decided_at')->latest('id')->get();
@endphp
<div class="card border-0 shadow-sm mb-3" id="committee-decision">
    <div class="card-body">
        <h2 class="h6">{{ __('dpc.committee_decision.heading') }}</h2>
        @can('committeeDecide', $proposal)
            <form method="POST" action="{{ route('sitting.decision', $proposal) }}" class="border rounded p-2 mb-3">
                @csrf
                @error('decision')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                {{-- The DPC Chairman alone accepts or rejects, before the sitting, with a
                     remark — required to reject (2026-11-18). A rejection goes to the
                     Secretary, who marks the candidates and sections at fault. --}}
                <p class="small text-muted mb-1">{{ __('dpc.committee_decision.chairman_help') }}</p>
                @foreach (['agreed', 'returned'] as $choice)
                    <label class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="decision" value="{{ $choice }}" required @checked(old('decision') === $choice)>
                        {{ __('dpc.committee_decision.chairman_choices.'.$choice) }}
                    </label>
                @endforeach
                <label class="form-label small mt-2" for="decision-remarks">{{ __('dpc.committee_decision.remarks') }}</label>
                <textarea class="form-control form-control-sm @error('remarks') is-invalid @enderror" id="decision-remarks" name="remarks" rows="2" maxlength="2000">{{ old('remarks') }}</textarea>
                @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button class="btn btn-primary btn-sm mt-2">{{ __('dpc.committee_decision.submit') }}</button>
            </form>
        @endcan

        {{-- The DPC Chairman sends the accepted DPC back to the Secretary, say on a member's
             Disagree, until its result is approved (2026-11-25). --}}
        @can('committeeReturn', $proposal)
            <form method="POST" action="{{ route('sitting.return', $proposal) }}" class="border border-danger rounded p-2 mb-3" id="chairman-return"
                  onsubmit="return confirm(@js(__('dpc.committee_decision.return_confirm')))">
                @csrf
                <p class="small text-muted mb-1">{{ __('dpc.committee_decision.return_help') }}</p>
                <label class="form-label small" for="return-remarks">{{ __('dpc.committee_decision.remarks') }}</label>
                <textarea class="form-control form-control-sm @error('return_remarks') is-invalid @enderror" id="return-remarks" name="return_remarks" rows="3" maxlength="20000" required>{{ old('return_remarks') }}</textarea>
                @error('return_remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button class="btn btn-danger btn-sm mt-2">{{ __('dpc.committee_decision.return_submit') }}</button>
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
