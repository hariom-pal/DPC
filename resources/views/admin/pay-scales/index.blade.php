@extends('layouts.admin')

@section('title', __('admin.pay_scales.index.pay_scales_2'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ __('admin.pay_scales.index.pay_scales') }}</h1>
        <a href="{{ route('admin.pay-scales.create') }}" class="btn btn-success btn-sm">{{ __('admin.pay_scales.index.new_pay_scale') }}</a>
    </div>

    <div class="card border-0 shadow-sm">
        @include('admin.partials.master-toolbar', ['master' => 'pay-scales'])

        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr><th>{{ __('ui.label') }}</th><th>{{ __('ui.code') }}</th><th>{{ __('ui.status') }}</th><th class="text-end">{{ __('ui.actions') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($payScales as $payScale)
                        <tr>
                            <td>{{ $payScale->label }}</td>
                            <td>{{ $payScale->code ?? '—' }}</td>
                            <td><x-status-badge :status="$payScale->status" /></td>
                            <td class="text-end">
                                <a href="{{ route('admin.pay-scales.edit', $payScale) }}" class="btn btn-outline-primary btn-sm">{{ __('ui.edit') }}</a>
                                <form method="POST" action="{{ route('admin.pay-scales.destroy', $payScale) }}" class="d-inline"
                                      data-confirm="{{ __('admin.pay_scales.index.delete_this_pay_scale') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">{{ __('ui.delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">{{ __('admin.pay_scales.index.no_pay_scales_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $payScales->links() }}</div>
@endsection
