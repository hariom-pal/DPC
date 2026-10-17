@extends('layouts.admin')

@section('title', __('admin.promotion_rules.title', ['class' => $class->name_en]))

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <a href="{{ route('admin.service-classes.index') }}" class="small">&larr; {{ __('admin.promotion_rules.back') }}</a>
            <h1 class="h4 mb-1">{{ __('admin.promotion_rules.title', ['class' => $class->name_en]) }}</h1>
            <p class="text-muted small mb-0">{{ __('admin.promotion_rules.intro') }}</p>
        </div>
    </div>

    @foreach ($fromClasses as $from)
        @php
            $rules = $history->get($from->code, collect());
            $inForce = $rules->first(fn ($rule) => $rule->effective_from_year <= $year);
        @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>{{ __('admin.promotion_rules.pair', ['from' => $from->name_en, 'to' => $class->name_en]) }}</strong>
                <a class="btn btn-primary btn-sm" href="{{ route('admin.service-classes.promotion-rules.create', [$class, 'from' => $from->code]) }}">{{ __('admin.promotion_rules.add') }}</a>
            </div>
            <div class="card-body">
                @if ($rules->isEmpty())
                    <p class="text-muted small mb-0">{{ __('admin.promotion_rules.none_yet') }}</p>
                @else
                    @if ($inForce)
                        <p class="small mb-2"><span class="badge text-bg-success">{{ __('admin.promotion_rules.in_force_now', ['year' => $year]) }}</span>
                            {{ __('admin.promotion_rules.from_year', ['year' => $inForce->effective_from_year]) }}</p>
                        @include('admin.promotion-rules.rule-summary', ['rule' => $inForce, 'dpcYear' => $year])
                    @endif

                    @php $others = $rules->reject(fn ($rule) => $inForce && $rule->is($inForce)); @endphp
                    @if ($others->isNotEmpty())
                        <details class="mt-3">
                            <summary class="small fw-semibold">{{ __('admin.promotion_rules.history', ['count' => $others->count()]) }}</summary>
                            @foreach ($others as $rule)
                                <div class="border rounded p-2 mt-2">
                                    <p class="small mb-2">
                                        <span class="badge {{ $rule->effective_from_year > $year ? 'text-bg-info' : 'text-bg-secondary' }}">{{ $rule->effective_from_year > $year ? __('admin.promotion_rules.upcoming') : __('admin.promotion_rules.earlier') }}</span>
                                        {{ __('admin.promotion_rules.from_year', ['year' => $rule->effective_from_year]) }} ·
                                        {{ $rule->recorder?->name ?? '—' }}, {{ $rule->created_at->format('d-m-Y') }}
                                    </p>
                                    @include('admin.promotion-rules.rule-summary', ['rule' => $rule, 'dpcYear' => max($rule->effective_from_year, $year)])
                                </div>
                            @endforeach
                        </details>
                    @endif
                @endif
            </div>
        </div>
    @endforeach
@endsection
