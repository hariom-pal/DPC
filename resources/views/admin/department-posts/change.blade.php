@extends($layout)

@section('title', __('admin.department_posts.changes.title'))

@section('content')
    @php
        $payload = $change->payload;
        $current = $change->post;
        $classLabel = function ($value) {
            $case = \App\Enums\ServiceClass::tryFrom((string) $value);

            return $case?->label() ?? ($value ?: '—');
        };
        $statusLabel = function ($value) {
            $case = \App\Enums\MasterStatus::tryFrom((string) $value);

            return $case?->label() ?? ($value ?: '—');
        };
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse((string) $value)->format('d-m-Y') : '—';
        $rows = [];
        if (in_array($change->action, ['create', 'update'], true)) {
            $rows = [
                [__('admin.department_posts.form.post_name'), $current?->name, $payload['name'] ?? null],
                [__('admin.department_posts.form.service_class'), $current?->class_level?->label(), $classLabel($payload['class_level'] ?? null)],
                [__('ui.pay_scale'), $current?->payScale?->label, $proposedPayScale?->label],
                [__('admin.department_posts.form.promotion_duration_years'), $current?->promotion_duration_years, $payload['promotion_duration_years'] ?? null],
                [__('admin.department_posts.form.effective_from'), $current?->effective_from?->format('d-m-Y'), $date($payload['effective_from'] ?? null)],
                [__('ui.status'), $current ? $statusLabel($current->status?->value ?? $current->status) : null, $statusLabel($payload['status'] ?? null)],
            ];
        } elseif ($change->action === 'supersede') {
            $rows = [
                [__('admin.department_posts.form.service_class'), $current?->class_level?->label(), $classLabel($payload['class_level'] ?? null)],
                [__('ui.pay_scale'), $current?->payScale?->label, $proposedPayScale?->label],
                [__('admin.department_posts.form.promotion_duration_years'), $current?->promotion_duration_years, $payload['promotion_duration_years'] ?? null],
                [__('admin.department_posts.form.effective_from'), $current?->effective_from?->format('d-m-Y'), $date($payload['effective_from'] ?? null)],
                [__('admin.department_posts.form.go_number'), null, $payload['government_order_number'] ?? null],
                [__('admin.department_posts.form.go_date'), null, $date($payload['government_order_date'] ?? null)],
            ];
        }
        $documentFor = function (string $field) use ($change) {
            foreach ($change->documents ?? [] as $index => $held) {
                if ($held['field'] === $field) {
                    return [$index, $held];
                }
            }

            return null;
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ __('admin.department_posts.changes.actions.'.$change->action) }}: {{ $current?->name ?? ($payload['name'] ?? '—') }}</h1>
            <div class="text-muted small">
                {{ $change->department?->name }} ·
                {{ __('admin.department_posts.changes.requested_by') }} {{ $change->requester?->name }}, {{ $change->created_at?->format('d-m-Y H:i') }}
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span @class(['badge', 'text-bg-warning' => $change->isPending(), 'text-bg-success' => $change->status === 'approved', 'text-bg-danger' => $change->status === 'rejected'])>
                {{ __('admin.department_posts.changes.statuses.'.$change->status) }}
            </span>
            <a href="{{ route($routePrefix.'.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('ui.back_to_list') }}</a>
        </div>
    </div>

    @include('partials.alerts')
    @error('change')<div class="alert alert-danger">{{ $message }}</div>@enderror

    @unless ($change->isPending())
        <div class="alert alert-light border small" id="change-decision">
            {{ __('admin.department_posts.changes.decided_by', ['name' => $change->decider?->name, 'at' => $change->decided_at?->format('d-m-Y H:i')]) }}
            @if ($change->remarks)<div class="mt-1"><strong>{{ __('admin.department_posts.changes.remarks') }}:</strong> {{ $change->remarks }}</div>@endif
            @if ($change->result)
                <div class="mt-1"><a href="{{ route($routePrefix.'.show', $change->result) }}">{{ __('admin.department_posts.changes.open_result') }}</a></div>
            @endif
        </div>
    @endunless

    @if ($change->action === 'delete')
        <div class="alert alert-warning">{{ __('admin.department_posts.changes.delete_lead', ['post' => $current?->name]) }}</div>
    @else
        <div class="card border-0 shadow-sm mb-3">
            <div class="table-responsive">
                <table class="table mb-0 align-middle" id="change-terms">
                    <thead>
                        <tr>
                            <th></th>
                            @if ($current)<th>{{ __('admin.department_posts.changes.now') }}</th>@endif
                            <th>{{ __('admin.department_posts.changes.requested') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as [$label, $now, $requested])
                            <tr @class(['table-warning' => $current && (string) $now !== (string) $requested && $requested !== null])>
                                <th scope="row" class="fw-normal">{{ $label }}</th>
                                @if ($current)<td>{{ $now ?? '—' }}</td>@endif
                                <td class="fw-semibold">{{ $requested ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @php($orders = (array) ($payload['orders'] ?? []))
        @if ($orders !== [] || $change->action === 'supersede')
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header fw-semibold">{{ __('admin.department_posts.show.authority') }}</div>
                <ul class="list-group list-group-flush">
                    @if ($change->action === 'supersede')
                        @php($held = $documentFor('government_order_document'))
                        <li class="list-group-item small">
                            {{ $payload['government_order_number'] ?? '—' }} · {{ $date($payload['government_order_date'] ?? null) }}
                            @if ($held)
                                · <a href="{{ route($routePrefix.'.changes.document', [$change, $held[0]]) }}">{{ $held[1]['original_name'] }}</a>
                            @endif
                        </li>
                    @endif
                    @foreach ($orders as $index => $order)
                        @php($held = $documentFor('orders.'.$index.'.document'))
                        <li class="list-group-item small">
                            {{ $order['government_order_number'] ?? '—' }} · {{ $date($order['government_order_date'] ?? null) }}
                            @if ($held)
                                · <a href="{{ route($routePrefix.'.changes.document', [$change, $held[0]]) }}">{{ $held[1]['original_name'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @if ($canApprove)
        <div class="card border-0 shadow-sm" id="change-decide">
            <div class="card-body d-flex flex-wrap gap-3 align-items-start">
                <form method="POST" action="{{ route('department.posts.changes.approve', $change) }}"
                      data-confirm="{{ __('admin.department_posts.changes.approve_confirm') }}">
                    @csrf
                    <button class="btn btn-success">{{ __('admin.department_posts.changes.approve') }}</button>
                </form>
                <form method="POST" action="{{ route('department.posts.changes.reject', $change) }}" class="flex-grow-1">
                    @csrf
                    <label class="form-label small" for="remarks">{{ __('admin.department_posts.changes.reject_remarks') }}</label>
                    <textarea class="form-control @error('remarks') is-invalid @enderror" id="remarks" name="remarks" rows="2" maxlength="1000" required>{{ old('remarks') }}</textarea>
                    @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <button class="btn btn-outline-danger btn-sm mt-2">{{ __('admin.department_posts.changes.reject') }}</button>
                </form>
            </div>
        </div>
    @endif
@endsection
