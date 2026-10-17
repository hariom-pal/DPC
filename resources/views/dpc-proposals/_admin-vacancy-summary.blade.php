{{--
    The Administrator's finalized Vacancy Management record behind a proposal, read-only.
    Expects: $proposal, $position (VacancyPosition|null), $counts (array|null), $vacancy (summary array), $year.
--}}
@if ($position === null)
    <div class="alert alert-warning mb-0" id="admin-vacancy-missing">
        <strong>{{ __('vacancymaster.proposal.not_finalized') }}</strong>
        <div class="small mt-1">{{ __('vacancymaster.proposal.not_finalized_help', [
            'department' => $proposal->department?->name,
            'post' => $proposal->promotionToPost?->name,
            'year' => $year,
        ]) }}</div>
    </div>
@else
    @php
        $frozen = $position->breakdown ?? [];
        $working = $frozen['working'] ?? [];
        $quota = $frozen['final_quota'] ?? [];
    @endphp
    <p class="small text-muted mb-2" id="admin-vacancy-source">
        {{ __('vacancymaster.proposal.source_line', [
            'department' => $proposal->department?->name,
            'post' => $position->post_name,
            'year' => $position->vacancy_year,
            'revision' => $position->revision,
            'at' => $position->finalized_at?->format('d-m-Y'),
        ]) }}
    </p>
    <div class="row g-2 mb-3">
        @foreach ([
            ['sanctioned', $frozen['sanctioned'] ?? null],
            ['working', $working['total'] ?? null],
            ['existing', $counts['existing'] ?? 0],
            ['anticipated', $counts['anticipated'] ?? 0],
            ['promotion_vacancy', $quota['promotion_vacancy'] ?? null],
            ['direct_vacancy', $quota['direct_vacancy'] ?? null],
        ] as [$key, $value])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="border rounded p-2 h-100">
                    <div class="text-muted small">{{ __("vacancymaster.proposal.figures.{$key}") }}</div>
                    <div class="h5 mb-0" data-admin-vacancy="{{ $key }}">{{ $value ?? '—' }}</div>
                </div>
            </div>
        @endforeach
    </div>
    <dl class="row small mb-2">
        <dt class="col-sm-4">{{ __('vacancymaster.proposal.figures.sanction_order') }}</dt>
        <dd class="col-sm-8">{{ ($frozen['sanctioned_order']['order_number'] ?? '—') }} · {{ isset($frozen['sanctioned_order']['order_date']) ? \Illuminate\Support\Carbon::parse($frozen['sanctioned_order']['order_date'])->format('d-m-Y') : '—' }}</dd>
        <dt class="col-sm-4">{{ __('vacancymaster.proposal.figures.working_split') }}</dt>
        <dd class="col-sm-8">{{ __('vacancymaster.proposal.figures.working_split_value', [
            'promotion' => $working['promotion'] ?? '—',
            'direct' => $working['direct'] ?? '—',
            'deputation' => $working['deputation'] ?? '—',
        ]) }}</dd>
        <dt class="col-sm-4">{{ __('vacancymaster.proposal.figures.promotion_share') }}</dt>
        <dd class="col-sm-8">{{ ($frozen['promotion_percentage'] ?? '—') }}% · {{ __('vacancymaster.proposal.figures.promotion_posts', ['posts' => $quota['promotion_chairs'] ?? '—']) }}</dd>
        <dt class="col-sm-4">{{ __('vacancymaster.proposal.figures.zone') }}</dt>
        <dd class="col-sm-8">{{ $vacancy['required_seniority'] ?? __('dpc.shared.no_cap') }}</dd>
    </dl>
    @if (($frozen['entries'] ?? []) !== [])
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('vacancymaster.proposal.figures.kind') }}</th>
                    <th>{{ __('vacancymaster.proposal.figures.reason') }}</th>
                    <th>{{ __('vacancymaster.proposal.figures.quota') }}</th>
                    <th>{{ __('ui.count') }}</th>
                    <th>{{ __('vacancymaster.proposal.figures.from') }}</th>
                    <th>{{ __('vacancymaster.proposal.figures.counted') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($frozen['entries'] as $entry)
                        <tr>
                            <td>{{ __('vacancymaster.kinds.'.$entry['kind']) }}</td>
                            <td>{{ __('vacancymaster.reasons.'.$entry['reason']) }}</td>
                            <td>{{ $entry['quota'] ? __('vacancymaster.quota.'.$entry['quota']) : '—' }}</td>
                            <td>{{ $entry['count'] }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($entry['effective_date'])->format('d-m-Y') }}</td>
                            <td>{{ $entry['kind'] === 'existing' ? '—' : ($entry['counted'] ? __('vacancymaster.yes') : __('vacancymaster.no')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="small text-muted mt-2 mb-0">{{ __('vacancymaster.proposal.read_only_note') }}</p>
@endif
