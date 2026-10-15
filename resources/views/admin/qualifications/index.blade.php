@extends('layouts.admin')
@section('title', __('admin.qualifications.index.qualifications'))
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.qualifications.index.qualification_master') }}</h1>
        <a href="{{ route('admin.qualifications.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <p class="text-body-secondary small">
{!! __('admin.qualifications.index.lead') !!}
    </p>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'qualifications'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.code') }}</th>
                        <th>{{ __('ui.name_en') }}</th>
                        <th>{{ __('ui.hi') }}</th>
                        <th>{{ __('ui.description') }}</th>
                        <th class="text-end">{{ __('admin.qualifications.index.used_by') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($qualifications as $qualification)
                        <tr>
                            <td><code>{{ $qualification->code }}</code></td>
                            <td>{{ $qualification->name_en }}</td>
                            <td>{{ $qualification->name_hi }}</td>
                            <td class="small text-body-secondary">{{ $qualification->description }}</td>
                            <td class="text-end">
                                @if ($qualification->recruitment_rule_sources_count > 0)
                                    {!! __('admin.qualifications.index.rule_count', ['count' => $qualification->recruitment_rule_sources_count]) !!}
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                            <td><x-status-badge :status="$qualification->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.qualifications.edit', $qualification) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.qualifications.destroy', $qualification) }}" class="d-inline"
                                      data-confirm="{{ __('admin.qualifications.index.delete_this_qualification') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm"
                                        @disabled($qualification->recruitment_rule_sources_count > 0)>{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ __('ui.no_records_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $qualifications->links() }}</div>
@endsection
