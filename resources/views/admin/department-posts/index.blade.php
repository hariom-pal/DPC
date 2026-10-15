@extends('layouts.admin')

@section('title', __('admin.department_posts.index.department_posts'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.department_posts.index.department_posts') }}</h1>
        <a href="{{ route('admin.department-posts.create') }}" class="btn btn-success btn-sm">{{ __('admin.department_posts.index.new_department_post') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'department-posts'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.post') }}</th><th>{{ __('ui.department') }}</th><th>{{ __('ui.class') }}</th><th>{{ __('ui.pay_scale') }}</th><th>{{ __('ui.promotion_duration') }}</th><th class="text-end">{{ __('admin.department_posts.form.sanctioned_strength') }}</th><th>{{ __('admin.department_posts.form.go_number') }}</th><th>{{ __('admin.department_posts.form.effective_from') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        {{-- Superseded mappings are dimmed rather than hidden: they
                             are why an old DPC can still be audited, so they belong
                             on the screen — just visibly behind the one in force. --}}
                        <tr @class(['table-light text-body-secondary' => ! $post->isInForce()])>
                            <td>
                                {{ $post->name }}
                                @unless ($post->isInForce())
                                    <span class="badge text-bg-secondary">{{ __('admin.department_posts.show.superseded') }}</span>
                                @endunless
                            </td>
                            <td>{{ $post->department?->name }}</td>
                            <td>{{ $post->class_level->label() }}</td>
                            <td>{{ $post->payScale?->label ?? '—' }}</td>
                            <td>{{ $post->promotion_duration_years === null ? '—' : __('ui.n_years', ['count' => $post->promotion_duration_years]) }}</td>
                            {{-- A dash here is not cosmetic: a mapping with no
                                 sanctioned strength cannot have a DPC raised
                                 against it, so the column is where an
                                 Administrator sees which posts are unusable. --}}
                            <td class="text-end">
                                @if ($post->sanctioned_strength === null)
                                    <span class="badge text-bg-warning">{{ __('admin.department_posts.index.strength_not_recorded') }}</span>
                                @else
                                    <strong>{{ $post->sanctioned_strength }}</strong>
                                @endif
                            </td>
                            <td class="small">
                                {{ $post->governmentOrderLabel() }}
                                {{-- Item 10: a mapping may hold several orders, and
                                     each may or may not have its scan on file. The
                                     badge counts the ones that do. --}}
                                @php $withPdf = $post->orders->filter->hasDocument()->count(); @endphp
                                @if ($withPdf > 0)
                                    <span class="badge text-bg-light">{{ __('admin.department_posts.index.pdf_on_file') }} ({{ $withPdf }})</span>
                                @endif
                            </td>
                            <td class="small">
                                {{ $post->effective_from?->format('d-m-Y') ?? '—' }}
                                — {{ $post->effective_to?->format('d-m-Y') ?? __('admin.department_posts.show.until_further_orders') }}
                            </td>
                            <td><x-status-badge :status="$post->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.department-posts.show', $post) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.view') }}</a>
                                @if ($post->isInForce())
                                    <a href="{{ route('admin.department-posts.edit', $post) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                @endif
                                @if ($post->isInForce())
                                    <form method="POST" action="{{ route('admin.department-posts.destroy', $post) }}" class="d-inline"
                                          data-confirm="{{ __('admin.department_posts.index.delete_this_post') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">{{ __('admin.department_posts.index.no_posts_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $posts->links() }}</div>
@endsection
