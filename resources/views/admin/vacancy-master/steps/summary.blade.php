{{-- Step 4. What is still missing, each item pointing at the step that fixes it. --}}
@php
    $issueStep = [
        'sanctioned_not_entered' => 'sanctioned',
        'working_not_entered' => 'working',
        'working_split_missing' => 'working',
        'working_exceeds_sanctioned' => 'working',
        'pending_entries' => 'vacancies',
        'existing_exceed_opening' => 'vacancies',
        'anticipated_exceed_working' => 'vacancies',
        'quota_overfilled' => 'vacancies',
    ];
@endphp
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>4. {{ __('vacancymaster.steps.summary') }}</strong></div>
    <div class="card-body">
        <p class="small text-muted">{{ __('vacancymaster.steps.summary_intro') }}</p>
        @if ($isDraft)
            @if ($b['issues'] !== [])
                <div class="alert alert-warning small mb-0">
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

{{-- How the vacancies for the year are worked out from the three steps. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><strong>{{ __('vacancymaster.steps.how_it_adds_up') }}</strong></div>
    <div class="card-body">
        <table class="table table-sm mb-3">
            <tbody>
                <tr><td>{{ __('vacancymaster.calc.sanctioned') }}</td><td class="text-end">{{ $b['sanctioned'] ?? __('vacancymaster.not_recorded') }}</td></tr>
                <tr><td>{{ __('vacancymaster.calc.working') }}</td><td class="text-end">{{ $b['working']['total'] ?? __('vacancymaster.not_entered') }}</td></tr>
                <tr class="table-light"><td>{{ __('vacancymaster.calc.opening') }}</td><td class="text-end">{{ $b['opening_vacancy'] ?? '—' }}</td></tr>
                <tr><td>{{ __('vacancymaster.calc.rule') }}</td>
                    <td class="text-end">
                        @if ($b['recruitment_rule_id'] === null)
                            {{ __('vacancymaster.not_recorded') }}
                        @else
                            {{ __('vacancymaster.calc.rule_value', ['version' => $b['recruitment_rule_version'], 'percentage' => $b['promotion_percentage'], 'rounding' => __('vacancymaster.rounding.'.$b['rounding'])]) }}
                        @endif
                    </td></tr>
                @if ($b['opening_quota'] !== null && $b['opening_quota']['promotion_chairs'] !== null)
                    <tr><td>{{ __('vacancymaster.calc.chairs') }}</td><td class="text-end">{{ __('vacancymaster.calc.chairs_value', ['promotion' => $b['opening_quota']['promotion_chairs'], 'direct' => $b['opening_quota']['direct_chairs'], 'exact' => number_format((float) $b['opening_quota']['promotion_exact'], 2)]) }}</td></tr>
                    <tr><td>{{ __('vacancymaster.calc.opening_quota') }}</td><td class="text-end">{{ __('vacancymaster.calc.quota_value', ['promotion' => $b['opening_quota']['promotion_vacancy'], 'direct' => $b['opening_quota']['direct_vacancy']]) }}</td></tr>
                @endif
                <tr><td>{{ __('vacancymaster.calc.existing_entries') }}</td><td class="text-end">{{ $b['existing_entries_total'] }}</td></tr>
                <tr><td>{{ __('vacancymaster.calc.anticipated_counted') }}</td><td class="text-end">{{ __('vacancymaster.calc.quota_value', ['promotion' => $b['anticipated_counted_promotion'], 'direct' => $b['anticipated_counted_direct']]) }}</td></tr>
                <tr><td>{{ __('vacancymaster.calc.anticipated_not_counted') }}</td><td class="text-end">{{ __('vacancymaster.calc.not_counted_value', ['opening' => $b['anticipated_already_in_opening'], 'pending' => $b['anticipated_pending'], 'not_eligible' => $b['anticipated_not_eligible']]) }}</td></tr>
                <tr class="table-success fw-semibold">
                    <td>{{ __('vacancymaster.calc.final') }}</td>
                    <td class="text-end">
                        @if ($b['final_quota'] !== null && $b['final_quota']['promotion_vacancy'] !== null)
                            {{ __('vacancymaster.calc.quota_value', ['promotion' => $b['final_quota']['promotion_vacancy'], 'direct' => $b['final_quota']['direct_vacancy']]) }}
                        @else
                            {{ __('vacancymaster.incomplete') }}
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="small text-muted mb-2">{{ __('vacancymaster.calc.formula') }}</p>
        <p class="small text-muted mb-2">{{ __('vacancymaster.calc.reconciliation_limit') }}</p>
    </div>
</div>

{{-- Finalize, or correct a finalized position by revision. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        @if ($isDraft)
            <form method="POST" action="{{ route('admin.departments.vacancy-master.finalize', [$department, $year, $post]) }}" data-confirm="{{ __('vacancymaster.finalize_confirm') }}">
                @csrf
                <button class="btn btn-success btn-sm" @disabled(! $b['complete'])>{{ __('vacancymaster.finalize') }}</button>
                @unless ($b['complete'])
                    <span class="small text-muted ms-2">{{ __('vacancymaster.finalize_blocked') }}</span>
                @endunless
            </form>
        @elseif ($isLatestFinalized)
            <p class="small mb-2">{{ __('vacancymaster.finalized_note', ['name' => $position->finalizer?->name ?? '—', 'at' => $position->finalized_at?->format('d-m-Y H:i') ?? '—']) }}</p>
            <form method="POST" action="{{ route('admin.departments.vacancy-master.revise', [$department, $year, $post]) }}">
                @csrf
                <label class="form-label small" for="revision_reason">{{ __('vacancymaster.revision_reason_label') }}</label>
                <textarea class="form-control form-control-sm mb-2" id="revision_reason" name="revision_reason" rows="2" maxlength="2000" required></textarea>
                <button class="btn btn-outline-warning btn-sm">{{ __('vacancymaster.revise') }}</button>
            </form>
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
