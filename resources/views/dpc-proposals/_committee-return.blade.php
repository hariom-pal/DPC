{{-- A DPC its DPC Chairman rejected, as it travels (2026-11-18): the Chairman's remark
     with the Secretary, who marks the candidates and sections at fault; the AH, who
     opens them for the AO; the AO, who corrects only them and sends it on — to the AH,
     the Secretary and so back to the committee. A return of the earlier kind (sections
     for every candidate) reads the same way. Expects: $proposal. --}}
@php($rejection = $proposal->committee_return)
@php($stage = $proposal->committeeReturnStage())
@if ($stage !== null)
    @php($rowNames = $proposal->evaluationRows()->pluck('name', 'id'))
    <div class="alert alert-warning" id="committee-return" data-committee-return-stage="{{ $stage }}">
        <div class="fw-semibold">{{ __('dpc.committee_rejection.banner', ['member' => $rejection['member'] ?? '—']) }}</div>
        @if (! empty($rejection['remarks']))
            <div class="small mb-1" id="committee-rejection-remark">{{ __('dpc.committee_rejection.remark') }}: {{ $rejection['remarks'] }}</div>
        @endif

        @if ($proposal->committeeReturnCandidates() !== [])
            <ul class="mb-1 small" id="committee-return-candidates">
                @foreach ($proposal->committeeReturnCandidates() as $rowId => $sections)
                    <li data-returned-candidate="{{ $rowId }}">
                        <strong>{{ $rowNames[$rowId] ?? '#'.$rowId }}</strong> —
                        @foreach ($sections as $section => $remark)
                            <span data-returned-section="{{ $section }}">{{ __('dpc.committee_decision.sections.'.$section) }}: {{ $remark }}</span>@if (! $loop->last); @endif
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @elseif (! empty($rejection['sections']))
            <ul class="mb-1 small">
                @foreach ((array) $rejection['sections'] as $section => $remark)
                    <li data-returned-section="{{ $section }}"><strong>{{ __('dpc.committee_decision.sections.'.$section) }}:</strong> {{ $remark }}</li>
                @endforeach
            </ul>
        @endif

        @error('committee_rejection')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
        @error('committee_resubmit')<div class="text-danger small mb-1">{{ $message }}</div>@enderror

        @can('secretaryMarkRejection', $proposal)
            <a href="{{ route('dpc-proposals.committee-rejection', $proposal) }}" class="btn btn-primary btn-sm" id="mark-rejection">{{ __('dpc.committee_rejection.mark') }}</a>
        @endcan

        @can('ahOpenSections', $proposal)
            <form method="POST" action="{{ route('dpc-proposals.committee-rejection.open', $proposal) }}" class="d-inline" data-confirm="{{ __('dpc.committee_rejection.open_confirm') }}">
                @csrf
                <button class="btn btn-primary btn-sm" id="open-for-ao">{{ __('dpc.committee_rejection.open_for_ao') }}</button>
            </form>
        @endcan

        @if ($stage === 'ao')
            <div class="small mb-2">{{ __('dpc.committee_decision.others_locked') }}</div>
            @can('committeeResubmit', $proposal)
                <a href="{{ route('dpc-proposals.employees.index', $proposal) }}" class="btn btn-outline-primary btn-sm">{{ __('dpc.committee_decision.correct') }}</a>
                <form method="POST" action="{{ route('dpc-proposals.committee-resubmit', $proposal) }}" class="d-inline" data-confirm="{{ __('dpc.committee_decision.resubmit_confirm') }}">
                    @csrf
                    <button class="btn btn-success btn-sm" id="committee-resubmit">{{ __('dpc.committee_decision.resubmit') }}</button>
                </form>
            @endcan
        @endif
    </div>
@endif
