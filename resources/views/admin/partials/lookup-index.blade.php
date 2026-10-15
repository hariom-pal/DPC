<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">{{ $title }}</h1>
    <a href="{{ route($routeBase.'.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
</div>

{{-- Search, Export CSV and Bulk Upload. `$master` is the registry key; the four
     screens sharing this partial each pass their own. Omitted, the toolbar is
     simply absent, so a screen that has not been described yet keeps working. --}}
@isset($master)
    @include('admin.partials.master-toolbar', ['master' => $master])
@endisset

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr><th>{{ __('ui.code') }}</th><th>{{ __('ui.name_en') }}</th><th>{{ __('ui.hi') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>{{ $item->code }}</td>
                        <td>{{ $item->name_en }}</td>
                        <td>{{ $item->name_hi }}</td>
                        <td><x-status-badge :status="$item->status" /></td>
                        <td class="text-end">
                            <a href="{{ route($routeBase.'.edit', $item) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                            <form method="POST" action="{{ route($routeBase.'.destroy', $item) }}" class="d-inline"
                                  data-confirm="{{ __('admin.partials.lookup_index.delete_this_record') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ __('ui.no_records_yet') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $items->withQueryString()->links() }}</div>
