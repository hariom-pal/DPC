@extends('layouts.admin')

@section('title', __('admin.reservation_categories.index.reservation_categories_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.reservation_categories.index.reservation_categories') }}</h1>
        <a href="{{ route('admin.reservation-categories.create') }}" class="btn btn-success btn-sm">{{ __('ui.new') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'reservation-categories'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.code') }}</th><th>{{ __('ui.name_en') }}</th><th>{{ __('ui.hi') }}</th><th>{{ __('admin.reservation_categories.index.reserved') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr>
                            <td>{{ $category->code }}</td>
                            <td>{{ $category->name_en }}</td>
                            <td>{{ $category->name_hi }}</td>
                            {{-- Built in Blade rather than as an HTML string inside a
                                 PHP ternary: a translated label cannot live inside a
                                 string literal, and escaping it here also means the
                                 markup is no longer emitted raw. --}}
                            <td>
                                @if ($category->is_reserved)
                                    <span class="badge text-bg-info">{{ __('admin.reservation_categories.index.reserved_2') }}</span>
                                @else
                                    <span class="badge text-bg-light">{{ __('admin.reservation_categories.index.unreserved') }}</span>
                                @endif
                            </td>
                            <td><x-status-badge :status="$category->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.reservation-categories.edit', $category) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.reservation-categories.destroy', $category) }}" class="d-inline"
                                      data-confirm="{{ __('admin.reservation_categories.index.delete_this_category') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">{{ __('admin.reservation_categories.index.no_categories_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $categories->links() }}</div>
@endsection
