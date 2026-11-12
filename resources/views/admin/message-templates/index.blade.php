@extends('layouts.admin')
@section('title', __('admin.message_templates.title'))
@section('content')
    <div class="mb-3">
        <h1 class="h4 mb-1">{{ __('admin.message_templates.title') }}</h1>
        <p class="text-muted small mb-0">{{ __('admin.message_templates.intro') }}</p>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle" id="message-templates">
                <thead>
                    <tr>
                        <th>{{ __('admin.message_templates.message') }}</th>
                        <th>{{ __('admin.message_templates.channel') }}</th>
                        <th>{{ __('admin.message_templates.wording') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th>{{ __('admin.message_templates.dlt') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-template="{{ $row['key'] }}|{{ $row['channel'] }}">
                            <td>
                                {{ $row['definition']['label'] }}
                                <div class="small text-muted" data-when>{{ $row['definition']['when'] }}</div>
                                <div class="small text-muted"><code>{{ $row['key'] }}</code></div>
                            </td>
                            <td>{{ __('admin.message_templates.channels.'.$row['channel']) }}</td>
                            <td>
                                @if ($row['saved'])
                                    <span class="badge text-bg-info">{{ __('admin.message_templates.custom') }}</span>
                                @else
                                    <span class="badge text-bg-light">{{ __('admin.message_templates.built_in') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($row['saved'] && ! $row['saved']->is_active)
                                    <span class="badge text-bg-secondary">{{ __('admin.message_templates.off') }}</span>
                                @else
                                    <span class="badge text-bg-success">{{ __('admin.message_templates.on') }}</span>
                                @endif
                            </td>
                            <td class="small">{{ $row['channel'] === 'sms' ? ($row['saved']?->dlt_template_id ?? '—') : '' }}</td>
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
