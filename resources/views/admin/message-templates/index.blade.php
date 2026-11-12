@extends('layouts.admin')
@section('title', __('admin.message_templates.titles.'.$channel))
@section('content')
    <div class="mb-3">
        <h1 class="h4 mb-1">{{ __('admin.message_templates.titles.'.$channel) }}</h1>
        <p class="text-muted small mb-1">{{ __('admin.message_templates.intro') }}</p>
        {{-- What the columns mean (2026-11-13). --}}
        <ul class="small text-muted mb-0" id="template-legend">
            <li>{!! __('admin.message_templates.legend.wording') !!}</li>
            <li>{!! __('admin.message_templates.legend.status') !!}</li>
            @if ($channel === 'sms')<li>{!! __('admin.message_templates.legend.dlt') !!}</li>@endif
        </ul>
    </div>

    {{-- No common DLT ID exists (2026-11-13): every SMS text needs its own. --}}
    @if ($missingDlt !== [])
        <div class="alert alert-warning" id="dlt-missing-warning" role="alert">
            <strong>{{ __('admin.message_templates.dlt_missing_heading', ['count' => count($missingDlt)]) }}</strong>
            <div class="small">{{ __('admin.message_templates.dlt_missing_help') }}</div>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle" id="message-templates" data-channel="{{ $channel }}">
                <thead>
                    <tr>
                        <th>{{ __('admin.message_templates.message') }}</th>
                        <th>{{ __('admin.message_templates.wording') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        @if ($channel === 'sms')<th>{{ __('admin.message_templates.dlt_in_use') }}</th>@endif
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $status = $row['saved']?->status ?? \App\Domain\Notification\Models\MessageTemplate::ACTIVE;
                            $inUse = $row['source']['customised'] ?? false;
                            $dltInUse = $row['source']['dlt_template_id'] ?? null;
                        @endphp
                        <tr data-template="{{ $row['key'] }}|{{ $row['channel'] }}" data-status="{{ $status }}">
                            <td>
                                {{ $row['definition']['label'] }}
                                <div class="small text-muted" data-when>{{ $row['definition']['when'] }}</div>
                                <div class="small text-muted"><code>{{ $row['key'] }}</code></div>
                            </td>
                            <td>
                                @if ($inUse)
                                    <span class="badge text-bg-info">{{ __('admin.message_templates.custom') }}</span>
                                @else
                                    <span class="badge text-bg-light">{{ __('admin.message_templates.built_in') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ ['active' => 'text-bg-success', 'draft' => 'text-bg-warning', 'inactive' => 'text-bg-secondary'][$status] }}">{{ __('admin.message_templates.statuses.'.$status) }}</span>
                            </td>
                            @if ($channel === 'sms')
                                <td class="small" data-dlt="{{ $row['source'] === null ? 'not-sent' : ($dltInUse === null ? 'missing' : 'set') }}">
                                    @if ($row['source'] === null)
                                        —
                                    @elseif ($dltInUse === null)
                                        <span class="badge text-bg-warning">{{ __('admin.message_templates.dlt_missing') }}</span>
                                    @else
                                        {{ $dltInUse }}
                                    @endif
                                </td>
                            @endif
                            <td class="text-end">
                                <a href="{{ route('admin.message-templates.edit', [$row['key'], $row['channel']]) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
