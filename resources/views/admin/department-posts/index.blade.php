@extends($layout)

@section('title', __('admin.department_posts.index.department_posts'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.department_posts.index.department_posts') }}</h1>
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-success btn-sm">{{ __('admin.department_posts.index.new_department_post') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'department-posts'])

        {{-- Four columns, so the list fits without sideways scrolling. The
             Government Orders, effective dates and promotion duration are on the
             detail screen ("View"), where each mapping is shown in full. --}}
        <p class="small text-muted px-3 pt-2 mb-0">{{ __('admin.department_posts.index.details_hint') }}</p>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>{{ __('ui.post') }}</th>
                        <th>{{ __('ui.class') }} · {{ __('ui.pay_scale') }}</th>
                        <th>{{ __('ui.status') }}</th>
                        <th class="text-end">{{ __('ui.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        {{-- Superseded mappings are dimmed rather than hidden: they
                             are why an old DPC can still be audited, so they belong
                             on the screen — just visibly behind the one in force. --}}
                        <tr @class(['table-light text-body-secondary' => ! $post->isInForce()])>
                            <td>
                                <a href="{{ route($routePrefix.'.show', $post) }}" class="fw-semibold text-decoration-none">{{ $post->name }}</a>
                                <div class="small text-muted">{{ $post->department?->name }}</div>
                            </td>
                            <td class="small">
                                {{ $post->class_level->label() }}
                                <div class="text-muted">{{ $post->payScale?->label ?? '—' }}</div>
                            </td>
                            <td>
                                @if ($post->isInForce())
                                    <x-status-badge :status="$post->status" />
                                @else
                                    <span class="badge text-bg-secondary">{{ __('admin.department_posts.show.superseded') }}</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route($routePrefix.'.show', $post) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.view') }}</a>
                                @if ($post->isInForce())
                                    <a href="{{ route($routePrefix.'.edit', $post) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                    <form method="POST" action="{{ route($routePrefix.'.destroy', $post) }}" class="d-inline"
                                          data-confirm="{{ __('admin.department_posts.index.delete_this_post') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">{{ __('admin.department_posts.index.no_posts_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $posts->links() }}</div>
@endsection
