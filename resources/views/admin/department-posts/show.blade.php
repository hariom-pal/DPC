@extends($layout)

@section('title', __('admin.department_posts.show.title'))

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $post->name }}</h1>
            <div class="text-muted small">
                {{ $post->department?->name ?? '—' }} ·
                {{ $post->class_level?->label() ?? '—' }} ·
                {{ $post->payScale?->label ?? __('ui.none') }}
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($post->isInForce())
                <span class="badge text-bg-success align-self-center">{{ __('admin.department_posts.show.in_force') }}</span>
                <a href="{{ route($routePrefix.'.edit', $post) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                <a href="{{ route($routePrefix.'.supersede.form', $post) }}" class="btn btn-warning btn-sm">
                    {{ __('admin.department_posts.show.supersede') }}
                </a>
            @elseif ($post->isSuperseded())
                <span class="badge text-bg-secondary align-self-center">{{ __('admin.department_posts.show.superseded') }}</span>
            @else
                {{-- A Draft or Inactive mapping: not in force, still editable (2026-10-30). --}}
                <x-status-badge :status="$post->status" />
                <a href="{{ route($routePrefix.'.edit', $post) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
            @endif
            <a href="{{ route($routePrefix.'.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.back_to_list') }}</a>
        </div>
    </div>

    @include('partials.alerts')

    {{-- The mapping's own terms — what a Pre-DPC reads off this post. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>{{ __('admin.department_posts.show.the_mapping') }}</strong></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3 fw-normal">{{ __('ui.department') }}</dt>
                <dd class="col-sm-9">{{ $post->department?->name ?? '—' }}</dd>
                <dt class="col-sm-3 fw-normal">{{ __('admin.department_posts.form.post_name') }}</dt>
                <dd class="col-sm-9">{{ $post->name }}</dd>
                <dt class="col-sm-3 fw-normal">{{ __('ui.pay_scale') }}</dt>
                <dd class="col-sm-9">{{ $post->payScale?->label ?? __('ui.none') }}</dd>
                <dt class="col-sm-3 fw-normal">{{ __('ui.class') }}</dt>
                <dd class="col-sm-9">{{ $post->class_level?->label() ?? '—' }}</dd>
                <dt class="col-sm-3 fw-normal">{{ __('ui.promotion_duration') }}</dt>
                <dd class="col-sm-9">
                    {{ $post->promotion_duration_years === null ? '—' : __('ui.n_years', ['count' => $post->promotion_duration_years]) }}
                </dd>
                {{-- Not a term of the mapping: recorded year by year in the
                     Vacancy Master, with its sanctioning order. --}}
                <dt class="col-sm-3 fw-normal">{{ __('admin.department_posts.form.sanctioned_strength') }}</dt>
                <dd class="col-sm-9">
                    {{ __('admin.department_posts.show.strength_in_vacancy_master') }}
                    @if ($post->department)
                        @if ($routePrefix === 'admin.department-posts')
                            <a href="{{ route('admin.departments.vacancy-master.index', $post->department) }}">{{ __('admin.department_posts.show.open_vacancy_master') }}</a>
                        @endif
                    @endif
                </dd>
                <dt class="col-sm-3 fw-normal">{{ __('admin.department_posts.form.effective_from') }}</dt>
                <dd class="col-sm-9">
                    {{ $post->effective_from?->format('d-m-Y') ?? '—' }}
                    @if ($post->effective_to)
                        — {{ $post->effective_to->format('d-m-Y') }}
                    @else
                        — {{ __('admin.department_posts.show.until_further_orders') }}
                    @endif
                </dd>
                <dt class="col-sm-3 fw-normal">{{ __('ui.status') }}</dt>
                <dd class="col-sm-9"><x-status-badge :status="$post->status" /></dd>
            </dl>
        </div>
    </div>

    {{-- The authority. Stated as its own card because it is what makes the terms
         above lawful, and it is what a challenge would ask to see. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><strong>{{ __('admin.department_posts.show.authority') }}</strong></div>
        <div class="card-body">
            {{-- Item 10 (2026-08-26): every Government Order bearing on this
                 mapping, each with its own number, date and PDF. Previous orders
                 stay here for audit; none of them is "replaced" by a later one —
                 they are all in force together, which is why there is no current
                 badge any more. --}}
            @if ($post->orders->isEmpty())
                <p class="small text-body-secondary mb-0">{{ __('admin.department_posts.not_recorded') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('admin.department_posts.form.go_number') }}</th>
                                <th>{{ __('admin.department_posts.form.go_date') }}</th>
                                <th>{{ __('admin.department_posts.show.document') }}</th>
                                <th>{{ __('admin.department_posts.show.uploaded') }}</th>
                                <th>{{ __('admin.department_posts.show.integrity') }}</th>
                                <th class="text-end">{{ __('ui.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($post->orders as $order)
                                <tr>
                                    <td>{{ $order->government_order_number }}</td>
                                    <td class="small">{{ $order->government_order_date->format('d-m-Y') }}</td>
                                    <td>
                                        @if ($order->hasDocument())
                                            {{ $order->original_name }}
                                            <span class="text-body-secondary small">({{ $order->sizeLabel() }})</span>
                                        @else
                                            <span class="text-body-secondary small">{{ __('admin.department_posts.show.no_document') }}</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $order->created_at?->format('d-m-Y H:i') }}
                                        @if ($order->uploader)
                                            · {{ $order->uploader->name }}
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if (! $order->hasDocument())
                                            —
                                        @elseif ($order->isIntact())
                                            <span class="text-success">{{ __('admin.department_posts.show.matches_hash') }}</span>
                                        @else
                                            <span class="text-danger">{{ __('admin.department_posts.show.does_not_match_hash') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($order->hasDocument())
                                            <a class="btn btn-outline-primary btn-sm"
                                               href="{{ route($routePrefix.'.documents.download', [$post, $order]) }}">
                                                {{ __('admin.department_posts.show.download') }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- The chain. What this post's mapping was before, and what replaced it —
         which is what lets a concluded DPC be audited against the order it ran
         under rather than against today's. --}}
    @if ($history->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>{{ __('admin.department_posts.show.history') }}</strong></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('admin.department_posts.form.effective_from') }}</th>
                            <th>{{ __('admin.department_posts.form.go_number') }}</th>
                            <th>{{ __('ui.pay_scale') }}</th>
                            <th>{{ __('ui.class') }}</th>
                            <th>{{ __('ui.promotion_duration') }}</th>
                            <th class="text-end">{{ __('ui.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $version)
                            <tr>
                                <td class="small">
                                    {{ $version->effective_from?->format('d-m-Y') ?? '—' }}
                                    — {{ $version->effective_to?->format('d-m-Y') ?? __('admin.department_posts.show.until_further_orders') }}
                                </td>
                                <td class="small">{{ $version->governmentOrderLabel() }}</td>
                                <td class="small">{{ $version->payScale?->label ?? '—' }}</td>
                                <td class="small">{{ $version->class_level?->label() ?? '—' }}</td>
                                <td class="small">
                                    {{ $version->promotion_duration_years === null ? '—' : __('ui.n_years', ['count' => $version->promotion_duration_years]) }}
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ route($routePrefix.'.show', $version) }}">
                                        {{ __('ui.view') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
