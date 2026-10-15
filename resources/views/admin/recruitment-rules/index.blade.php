@extends('layouts.admin')

@section('title', __('admin.recruitment_rules.index.recruitment_rules_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.recruitment_rules.index.recruitment_rules') }}</h1>
        <a href="{{ route('admin.recruitment-rules.create') }}" class="btn btn-success btn-sm">{{ __('admin.recruitment_rules.index.new_rule') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.post') }}</th><th>{{ __('ui.ver') }}</th><th>{{ __('admin.recruitment_rules.index.go_no') }}</th><th>{{ __('admin.recruitment_rules.index.promotion_from_posts_min_qualification') }}</th><th>{{ __('admin.recruitment_rules.index.effective') }}</th>
                        <th>{{ __('admin.recruitment_rules.index.direct') }}</th><th>{{ __('admin.recruitment_rules.index.promo') }}</th><th>{{ __('ui.go_pdf') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td>{{ $rule->post?->name }}</td>
                            <td>v{{ $rule->version }}</td>
                            <td>{{ $rule->government_order_number }}</td>
                            <td class="small">
                                @forelse ($rule->promotionSources as $feeder)
                                    <div>
                                        {{ $feeder->promotionFromPost?->name ?? '—' }}
                                        <span class="text-body-secondary">
                                            {{ __('admin.recruitment_rules.index.share', ['percent' => rtrim(rtrim(number_format((float) $feeder->promotion_percentage, 2, '.', ''), '0'), '.')]) }}
                                            @if ($feeder->experienceLabel())
                                                · {{ $feeder->experienceLabel() }}
                                            @endif
                                            @if ($feeder->qualificationLabel())
                                                · {{ $feeder->qualificationLabel() }}
                                                @if ($feeder->qualifications->isEmpty())
                                                    {{-- Legacy free text that matched no master row. Since
                                                         item 11 the master link is a pivot, so "unmapped"
                                                         means the pivot is empty and the label came from
                                                         `min_qualification`. --}}
                                                    <span class="badge text-bg-warning" title="{{ __('admin.recruitment_rules.index.not_linked_to_the_qualification_master') }}">{{ __('admin.recruitment_rules.index.unmapped') }}</span>
                                                @endif
                                            @endif
                                        </span>
                                    </div>
                                @empty
                                    <span class="text-body-secondary">{{ __('admin.recruitment_rules.index.direct_recruitment_only') }}</span>
                                @endforelse
                            </td>
                            <td>{{ $rule->effective_from?->format('d-m-Y') }} — {{ $rule->effective_to?->format('d-m-Y') ?? 'current' }}</td>
                            <td>{{ $rule->direct_recruitment_pct }}</td>
                            <td>{{ $rule->promotion_recruitment_pct }}</td>
                            <td>
                                @if ($rule->go_document_path)
                                    <a href="{{ route('admin.recruitment-rules.download', $rule) }}">{{ __('admin.recruitment_rules.index.pdf') }}</a>
                                @else — @endif
                            </td>
                            <td><x-status-badge :status="$rule->status" /></td>
                            <td class="text-end text-nowrap">
                                @if ($rule->status->value === 'draft')
                                    <a href="{{ route('admin.recruitment-rules.edit', $rule) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                @endif
                                <form method="POST" action="{{ route('admin.recruitment-rules.version', $rule) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-secondary btn-sm">{{ __('admin.recruitment_rules.index.new_version') }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.recruitment-rules.destroy', $rule) }}" class="d-inline"
                                      data-confirm="{{ __('admin.recruitment_rules.index.delete_this_rule_version') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">{{ __('admin.recruitment_rules.index.no_recruitment_rules_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $rules->links() }}</div>
@endsection
