@extends('layouts.app')
@section('title', __('vacancymaster.proposal.page_title'))
@section('content')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="{{ route('dpc-proposals.show', $proposal) }}">{{ $proposal->dpc_id ?? __('dpc.record_gaps.proposal') }}</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ __('vacancymaster.proposal.page_title') }}</li>
        </ol>
    </nav>

    @include('partials.alerts')

    <h1 class="h4 mb-1">{{ __('vacancymaster.proposal.page_title') }}</h1>
    <p class="text-muted mb-3">{{ __('vacancymaster.proposal.page_lead') }}</p>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            @include('dpc-proposals._admin-vacancy-summary')
        </div>
    </div>

    {{-- Step 1: the Working Officers List, reconciled with the record above. --}}
    @if ($canEdit && $position !== null)
        <p class="small text-muted mb-2">{{ __('vacancymaster.proposal.working_list.lead') }}</p>
        @include('dpc-proposals.imports._upload', ['template' => \App\Domain\Import\Enums\ImportTemplate::from('currently_working_us')])
    @endif
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            @include('dpc-proposals._working-list-reconciliation')
        </div>
    </div>

    <a href="{{ route('dpc-proposals.show', $proposal) }}" class="btn btn-outline-secondary">{{ __('ui.back') }}</a>
@endsection
