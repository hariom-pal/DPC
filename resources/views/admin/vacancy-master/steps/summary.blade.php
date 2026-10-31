{{-- Step 4. What is still missing, the calculation, then finalize (2026-10-30). --}}
@php
    $issueStep = [
        'sanctioned_not_entered' => 'sanctioned',
        'working_not_entered' => 'working',
        'working_exceeds_sanctioned' => 'working',
        'pending_entries' => 'vacancies',
        'total_exceeds_sanctioned' => 'vacancies',
    ];
@endphp
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>4. {{ __('vacancymaster.steps.summary') }}</strong></div>
    <div class="card-body">
        @if ($isDraft)
            @if ($b['issues'] !== [])
                <div class="alert alert-warning small mb-0" id="vacancy-issues">
                    <strong>{{ __('vacancymaster.steps.missing_heading') }}</strong>
                    <ul class="mb-0">
                        @foreach ($b['issues'] as $issue)
                            <li>
                                {{ __('vacancymaster.issues.'.$issue['key'], $issue['params']) }}
                                @if (isset($issueStep[$issue['key']]))
                                    — <a href="{{ $stepUrl($issueStep[$issue['key']]) }}">{{ __('vacancymaster.steps.fix_in', ['n' => $steps[$issueStep[$issue['key']]]['n']]) }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="alert alert-success small mb-0">{{ __('vacancymaster.steps.nothing_missing') }}</div>
            @endif
        @endif
    </div>
</div>

{{-- How the vacancy is worked out: A + B1 + B2 + C, then the Pre-DPC's share. --}}
<div class="card border-0 shadow-sm mb-3" id="vacancy-calculation">
    <div class="card-header bg-white"><strong>{{ __('vacancymaster.steps.how_it_adds_up') }}</strong></div>
    <div class="card-body">
        <table class="table table-sm mb-3">
            <tbody>
                <tr><td>{{ __('vacancymaster.calc.sanctioned') }}</td><td class="text-end">{{ $b['sanctioned'] ?? __('vacancymaster.not_recorded') }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.working_total_calc', ['own' => $b['working']['own'] ?? '—', 'incoming' => $b['working']['deputation_in'] ?? 0]) }}</td><td class="text-end">{{ $b['working']['total'] ?? __('vacancymaster.not_entered') }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.a_clear') }}</td><td class="text-end" data-figure="a">{{ $b['clear_vacancy'] ?? '—' }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.b1_short') }}</td><td class="text-end" data-figure="b1">{{ $b['deputation_in_vacancy'] }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.b2_short') }}</td><td class="text-end" data-figure="b2">{{ $b['deputation_out_vacancy'] }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.c_short') }}</td><td class="text-end" data-figure="c">{{ $b['anticipated_vacancy'] }}</td></tr>
                <tr class="table-light fw-semibold"><td>{{ __('vacancymaster.pm.total_calc') }}</td><td class="text-end" data-figure="total">{{ $b['total_vacancy'] ?? '—' }}</td></tr>
                <tr><td>{{ __('vacancymaster.pm.percentage', ['pre_dpc' => $b['pre_dpc_number'] ?? '—']) }}</td>
                    <td class="text-end">{{ $b['promotion_percentage'] === null ? __('vacancymaster.not_recorded') : $b['promotion_percentage'].'% · '.__('vacancymaster.rounding.'.$b['rounding']) }}</td></tr>
                <tr class="table-success fw-semibold">
                    <td>{{ __('vacancymaster.pm.promotion_vacancy') }}</td>
                    <td class="text-end" data-figure="promotion">
                        @if (($b['final_quota']['promotion_vacancy'] ?? null) !== null)
                            {{ $b['final_quota']['promotion_vacancy'] }}
                            <span class="small text-muted fw-normal">({{ number_format((float) $b['final_quota']['promotion_exact'], 2) }})</span>
                        @else
                            {{ __('vacancymaster.incomplete') }}
                        @endif
                    </td>
                </tr>
                <tr><td>{{ __('vacancymaster.pm.direct_vacancy') }}</td><td class="text-end" data-figure="direct">{{ $b['final_quota']['direct_vacancy'] ?? '—' }}</td></tr>
            </tbody>
        </table>
        <p class="small text-muted mb-0">{{ __('vacancymaster.pm.formula') }}</p>
    </div>
</div>

{{-- Finalize, or correct a finalized position by revision. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        @if ($editable)
            <form method="POST" action="{{ route($records.'.finalize', [$department, $year, $post]) }}" data-confirm="{{ __('vacancymaster.finalize_confirm') }}">
                @csrf
                <button class="btn btn-success btn-sm" @disabled(! $b['complete'])>{{ __('vacancymaster.finalize') }}</button>
                @unless ($b['complete'])
                    <span class="small text-muted ms-2">{{ __('vacancymaster.finalize_blocked') }}</span>
                @endunless
            </form>
        @elseif ($isLatestFinalized)
            <p class="small mb-2">{{ __('vacancymaster.finalized_note', ['name' => $position->finalizer?->name ?? '—', 'at' => $position->finalized_at?->format('d-m-Y H:i') ?? '—']) }}</p>
            @if ($canManage)
            <form method="POST" action="{{ route($records.'.revise', [$department, $year, $post]) }}">
                @csrf
                <label class="form-label small" for="revision_reason">{{ __('vacancymaster.revision_reason_label') }}</label>
                <textarea class="form-control form-control-sm mb-2 @error('revision_reason') is-invalid @enderror" id="revision_reason" name="revision_reason" rows="2" maxlength="2000" required @error('revision_reason') autofocus @enderror>{{ old('revision_reason') }}</textarea>
@include('admin.vacancy-master.partials.field-error', ['field' => 'revision_reason'])
                <button class="btn btn-outline-warning btn-sm">{{ __('vacancymaster.revise') }}</button>
            </form>
            @endif
        @endif
    </div>
</div>

{{-- Every revision of this position, newest first. --}}
@if ($revisions->isNotEmpty())
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>{{ __('vacancymaster.history_heading') }}</strong></div>
        <div class="table-responsive">
            <table class="table table-sm small mb-0">
                <thead><tr>
                    <th>{{ __('vacancymaster.col.revision') }}</th><th>{{ __('ui.status') }}</th>
                    <th class="text-end">{{ __('vacancymaster.col.sanctioned') }}</th>
                    <th class="text-end">{{ __('vacancymaster.col.existing') }}</th>
                    <th class="text-end">{{ __('vacancymaster.col.promotion') }}</th>
                    <th class="text-end">{{ __('vacancymaster.col.direct') }}</th>
                    <th>{{ __('vacancymaster.col.finalized') }}</th><th>{{ __('vacancymaster.revision_reason_label') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($revisions as $revision)
                        <tr>
                            <td>{{ $revision->revision }}</td>
                            <td>{{ $revision->status->label() }}</td>
                            <td class="text-end">{{ $revision->sanctioned_strength ?? '—' }}</td>
                            <td class="text-end">{{ $revision->opening_vacancy ?? '—' }}</td>
                            <td class="text-end">{{ $revision->promotion_vacancy ?? '—' }}</td>
                            <td class="text-end">{{ $revision->direct_vacancy ?? '—' }}</td>
                            <td>{{ $revision->finalized_at ? $revision->finalized_at->format('d-m-Y H:i').' · '.($revision->finalizer?->name ?? '—') : '—' }}</td>
                            <td>{{ $revision->revision_reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
