{{-- PPT S51 — "SELF INSPECTION AND ASSESMENT / ACTION"

     "DATA BEING CONSIDER AS PROVIDED BY YOUR DEPARTMENT FOR ONLINE DPC"

     The slide's two buttons are CLOSE and PRINT, and those are the two here.
     There is no RAISE OBJECTION, because the deck has none — the notice sends
     the officer to the department in person (BD-20). --}}

@extends('layouts.portal')

@section('title', __('selfinspection.show.self_inspection_my_data'))

@section('content')
    <div class="py-4 py-lg-5" style="background:var(--gov-surface)">
        <div class="container">

            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
                <div>
                    <h1 class="h4 mb-1">{{ __('selfinspection.show.my_data_as_recorded_for_this_dpc') }}</h1>
                    <p class="text-body-secondary small mb-0">
                        {{ __('ui.data_being_considered_as_provided_by_your_depart') }}
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('self-inspection.print', ['row' => $selected->id]) }}"
                       class="btn btn-outline-primary" target="_blank" rel="noopener">
{{ __('selfinspection.show.print') }}
                    </a>
                    <form method="POST" action="{{ route('self-inspection.close') }}">
                        @csrf
                        <button class="btn btn-outline-secondary">{{ __('selfinspection.show.close') }}</button>
                    </form>
                </div>
            </div>

            @include('partials.alerts')

            {{-- An officer can be under consideration in more than one DPC at
                 once — two different promotions, or a Review of a DPC they were
                 in. The deck shows a single row because its example has one;
                 hiding the others would be showing the officer less than the
                 record holds. --}}
            @if ($rows->count() > 1)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
{{ __('selfinspection.show.choose_proposal', ['count' => $rows->count()]) }}
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($rows as $option)
                                <a href="{{ route('self-inspection.show', ['row' => $option->id]) }}"
                                   class="btn btn-sm {{ $option->is($selected) ? 'btn-primary' : 'btn-outline-primary' }}">
                                    {{ $option->proposal->dpc_code ?? 'Proposal #'.$option->dpc_proposal_id }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-3">{{ __('ui.department') }}</dt>
                        <dd class="col-sm-9">{{ $department->name }}</dd>

                        <dt class="col-sm-3">{{ __('ui.dpc') }}</dt>
                        <dd class="col-sm-9">
                            {{ $proposal->dpc_code ?? __('ui.screen.no_dpc_id_yet') }}
                            <span class="text-body-secondary">
                                · {{ $proposal->status->label() }}
                            </span>
                        </dd>

                        <dt class="col-sm-3">{{ __('ui.promotion') }}</dt>
                        <dd class="col-sm-9">
                            {{ $proposal->promotionFromPost?->name ?? '—' }}
                            &rarr;
                            {{ $proposal->promotionToPost?->name ?? '—' }}
                        </dd>

                        <dt class="col-sm-3">{{ __('ui.committee_sitting') }}</dt>
                        <dd class="col-sm-9 mb-0">
                            {{ $sitting_date?->format('d-m-Y') ?? __('ui.screen.not_scheduled_yet') }}
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    @include('self-inspection.partials.record', [
                        'row' => $row, 'years' => $years,
                        'cr' => $cr, 'loyalty' => $loyalty, 'property' => $property,
                    ])
                </div>
            </div>

            @include('self-inspection.partials.notice', [
                'deadlineText' => $presenter->formattedDeadline($objection_deadline),
                'deadlinePassed' => $objection_deadline_passed,
            ])

            {{-- Said plainly, because its absence is the question an officer will
                 ask first. --}}
            <p class="small text-body-secondary mt-3 mb-0">
                {{ __('selfinspection.show.this_screen_shows_the_data_your_department_suppl') }}
            </p>
        </div>
    </div>
@endsection
