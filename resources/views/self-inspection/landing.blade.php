{{-- PPT S49 — "SELF INSPECTION AND ASSESMENT"

     विभागीय अधिकारीयों व कर्मचारियों को स्वयं के डाटा अवलोकन की सुविधा
     — the facility for a departmental officer to view their own data.

     The slide is an introduction, so this screen is one: it says what the
     facility is, what it shows, and what to do if the data is wrong. That last
     part is the deck's own answer and it is not an online form — S51 sends the
     officer to the department in person. --}}

@extends('layouts.portal')

@section('title', __('selfinspection.landing.self_inspection_assessment_e_dpc_portal'))

@section('content')
    <div class="py-5" style="background:linear-gradient(180deg,#eef4fb 0%,var(--gov-surface) 55%)">
        <div class="container py-lg-4">
            <div class="row justify-content-center g-4">
                <div class="col-lg-7">
                    <div class="gov-card p-4 p-lg-5">
                        <div class="text-center mb-4">
                            @include('partials.logo', [
                                'which' => 'cgpsc', 'class' => 'mb-3', 'style' => 'height:56px', 'alt' => '',
                            ])
                            <h1 class="h4 mb-1">{{ __('selfinspection.landing.self_inspection_amp_assessment') }}</h1>
                            <p class="text-body-secondary small mb-0">
                                {{ __('selfinspection.landing.text') }}
                            </p>
                        </div>

                        @include('partials.alerts')

                        @if (! $enabled)
                            <div class="alert alert-secondary" role="status">
                                <strong class="d-block mb-1">{{ __('selfinspection.landing.self_inspection_is_currently_closed') }}</strong>
                                <span class="small">
                                    {{ __('selfinspection.landing.the_facility_is_opened_by_the_commission_while_d') }}
                                </span>
                            </div>
                        @elseif ($pending !== null)
                            {{-- A structured refusal, not an error: the case is
                                 recognised and the answer is genuinely unknown. --}}
                            <div class="alert alert-warning" role="status">
                                <strong class="d-block mb-1">{{ $pending->title }}</strong>
                                <p class="small mb-2">{{ $pending->question }}</p>
                                @if ($pending->notDone !== [])
                                    <ul class="small mb-0 ps-3">
                                        @foreach ($pending->notDone as $line)
                                            <li>{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @else
                            <p>
{!! __('selfinspection.landing.lead') !!}
                            </p>

                            <ul class="list-unstyled d-grid gap-3 my-4">
                                @foreach ([
                                    ['eye', 'What you will see', 'Your date of birth, qualification, date of joining the current post, category, and your year-wise CR grades, loyalty and immovable-property records — exactly as your department supplied them.'],
                                    ['shield', 'What you will not see', 'No marks, no remarks and no promotion decision. Those are produced by the Committee, which has not yet sat.'],
                                    ['audit', 'Every visit is recorded', 'The date, time and outcome of each attempt to open this screen is written to a permanent record.'],
                                ] as [$icon, $title, $text])
                                    <li class="d-flex gap-3">
                                        <span class="gov-icon flex-shrink-0">
                                            @include('partials.icon', ['name' => $icon, 'size' => 20])
                                        </span>
                                        <span>
                                            <strong class="d-block small">{{ $title }}</strong>
                                            <span class="small text-body-secondary">{{ $text }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            <a href="{{ route('self-inspection.identify') }}" class="btn btn-primary btn-lg w-100">
                                {{ __('selfinspection.landing.view_my_data') }}
                            </a>
                        @endif

                        <hr class="my-4">

                        {{-- S51's own instruction, stated here as well so an
                             officer knows the route before they see the data. --}}
                        <p class="small text-body-secondary mb-0">
{!! __('selfinspection.landing.if_wrong') !!}
                        </p>
                    </div>

                    <p class="text-center small text-body-secondary mt-3 mb-0">
{!! __('selfinspection.landing.for_officers_only', [
                            'link' => '<a href="'.e(route('login')).'">'.e(__('selfinspection.landing.sign_in')).'</a>',
                        ]) !!}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
