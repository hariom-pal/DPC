@extends('layouts.admin')

@section('title', __('admin.portal_notices.index.heading'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">{{ __('admin.portal_notices.index.heading') }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.portal_notices.index.lead') }}</p>
        </div>
        <a href="{{ route('admin.portal-notices.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('admin.portal_notices.index.title') }}</th>
                        <th>{{ __('admin.portal_notices.index.published') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notices as $notice)
                        <tr>
                            <td>
                                {{ $notice->title_en }}
                                @unless ($notice->title_hi)
                                    <span class="badge text-bg-light">{{ __('admin.portal_notices.index.english_only') }}</span>
                                @endunless
                            </td>
                            <td class="small">
                                {{ $notice->published_from->format('d-m-Y') }}
                                @if ($notice->published_until)
                                    — {{ $notice->published_until->format('d-m-Y') }}
                                @else
                                    — {{ __('admin.portal_notices.index.until_further_notice') }}
                                @endif
                            </td>
                            <td><x-status-badge :status="$notice->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.portal-notices.edit', $notice) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.portal-notices.destroy', $notice) }}" class="d-inline"
                                      data-confirm="{{ __('admin.portal_notices.index.delete_confirm') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">{{ __('admin.portal_notices.index.none_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $notices->links() }}</div>
@endsection
