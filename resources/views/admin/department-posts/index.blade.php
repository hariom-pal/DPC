@extends($layout)

@section('title', __('admin.department_posts.index.department_posts'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.department_posts.index.department_posts') }}</h1>
        @if ($canWrite)
            <a href="{{ route($routePrefix.'.create') }}" class="btn btn-success btn-sm">{{ $needsApproval ? __('admin.department_posts.changes.request_new') : __('admin.department_posts.index.new_department_post') }}</a>
        @endif
    </div>
    @unless ($canWrite)
        <p class="text-muted small" id="post-mapping-read-only">{{ __('admin.department_posts.index.read_only') }}</p>
    @endunless
    @if ($needsApproval)
        {{-- The Authorized Officer's changes wait for the Department Admin (2026-11-03). --}}
        <p class="text-muted small" id="post-mapping-needs-approval">{{ __('admin.department_posts.changes.needs_approval_note') }}</p>
    @endif
    @error('change')<div class="alert alert-danger">{{ $message }}</div>@enderror

    {{-- The Administrator narrows the list to one department (2026-11-03). --}}
    @if ($filterDepartments->isNotEmpty())
        <form method="GET" class="row g-2 align-items-end mb-3" id="post-mapping-department-filter">
            @if (request()->filled('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
            <div class="col-sm-6 col-lg-4">
                <label class="form-label small mb-1" for="filter_department_id">{{ __('ui.department') }}</label>
                <select class="form-select form-select-sm" id="filter_department_id" name="department_id">
                    <option value="">{{ __('admin.department_posts.index.all_departments') }}</option>
                    @foreach ($filterDepartments as $department)
                        <option value="{{ $department->id }}" @selected($departmentFilter === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary btn-sm">{{ __('admin.department_posts.index.filter') }}</button>
                @if ($departmentFilter !== null)
                    <a href="{{ route($routePrefix.'.index', request()->filled('q') ? ['q' => request('q')] : []) }}" class="btn btn-outline-secondary btn-sm">{{ __('admin.department_posts.index.clear_filter') }}</a>
                @endif
            </div>
        </form>
    @endif

    @if ($pendingChanges->isNotEmpty())
        <div class="card border-warning shadow-sm mb-3" id="post-mapping-pending">
            <div class="card-header bg-warning-subtle fw-semibold">{{ __('admin.department_posts.changes.pending_title', ['count' => $pendingChanges->count()]) }}</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('admin.department_posts.changes.request') }}</th>
                            <th>{{ __('ui.post') }}</th>
                            <th>{{ __('admin.department_posts.changes.requested_by') }}</th>
                            <th class="text-end">{{ __('ui.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingChanges as $change)
                            <tr>
                                <td>{{ __('admin.department_posts.changes.actions.'.$change->action) }}</td>
                                <td>
                                    {{ $change->post?->name ?? ($change->payload['name'] ?? '—') }}
                                    <div class="small text-muted">{{ $change->department?->name }}</div>
                                </td>
                                <td class="small">{{ $change->requester?->name }}<div class="text-muted">{{ $change->created_at?->format('d-m-Y H:i') }}</div></td>
                                <td class="text-end">
                                    <a href="{{ route($routePrefix.'.changes.show', $change) }}" class="btn btn-sm {{ $canApprove ? 'btn-warning' : 'btn-outline-secondary' }}">
                                        {{ $canApprove ? __('admin.department_posts.changes.review') : __('ui.view') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($decidedChanges->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3" id="post-mapping-decided">
            <div class="card-header fw-semibold">{{ __('admin.department_posts.changes.decided_title') }}</div>
            <ul class="list-group list-group-flush">
                @foreach ($decidedChanges as $change)
                    <li class="list-group-item small">
                        <span @class(['badge', 'text-bg-success' => $change->status === 'approved', 'text-bg-danger' => $change->status === 'rejected'])>{{ __('admin.department_posts.changes.statuses.'.$change->status) }}</span>
                        {{ __('admin.department_posts.changes.actions.'.$change->action) }}:
                        <a href="{{ route($routePrefix.'.changes.show', $change) }}">{{ $change->post?->name ?? ($change->payload['name'] ?? '—') }}</a>
                        · {{ $change->decider?->name }} · {{ $change->decided_at?->format('d-m-Y H:i') }}
                        @if ($change->remarks)<div class="text-muted">{{ $change->remarks }}</div>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

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
                        <tr @class(['table-light text-body-secondary' => $post->isSuperseded()])>
                            <td>
                                <a href="{{ route($routePrefix.'.show', $post) }}" class="fw-semibold text-decoration-none">{{ $post->name }}</a>
                                <div class="small text-muted">{{ $post->department?->name }}</div>
                            </td>
                            <td class="small">
                                {{ $post->class_level->label() }}
                                <div class="text-muted">{{ $post->payScale?->label ?? '—' }}</div>
                            </td>
                            <td>
                                @if ($post->isSuperseded())
                                    <span class="badge text-bg-secondary">{{ __('admin.department_posts.show.superseded') }}</span>
                                @else
                                    <x-status-badge :status="$post->status" />
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route($routePrefix.'.show', $post) }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.view') }}</a>
                                @if ($canWrite && ! $post->isSuperseded())
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
