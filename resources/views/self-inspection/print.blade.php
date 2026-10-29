{{-- S51's PRINT.

     Standalone rather than an extension of the portal layout: the officer may
     carry this to their department, so it carries what makes it checkable —
     the department, the DPC, the sitting date, the deadline, and the moment it
     was printed — and none of the portal chrome.

     The data table is the **same partial** the screen uses. The paper an officer
     presents cannot be allowed to disagree with what they were shown. --}}

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {!! __('selfinspection.print.title', ['name' => e($row->name)]) !!}
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
    <style>
        /* The controls belong to the screen, not to the paper. */
        @media print {
            .d-print-none { display: none !important; }
            .alert { border: 1px solid #999 !important; }
        }
    </style>
</head>
<body>
<div class="container py-4" style="max-width:1100px">

    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
        <div>
            <h1 class="h5 mb-1">{{ __('selfinspection.print.self_inspection_amp_assessment_officer_s_own_dat') }}</h1>
            <p class="small text-body-secondary mb-0">
                {{ __('selfinspection.print.chhattisgarh_public_service_commission_online_dp') }}
            </p>
        </div>
        <div class="text-end small text-body-secondary">
{{ __('selfinspection.print.printed_at', ['at' => $printed_at->format('d-m-Y H:i')]) }}
        </div>
    </div>

    <dl class="row small mb-4">
        <dt class="col-sm-3">{{ __('ui.department') }}</dt>
        <dd class="col-sm-9">{{ $department->name }}</dd>

        <dt class="col-sm-3">{{ __('ui.dpc') }}</dt>
        <dd class="col-sm-9">{{ $proposal->dpc_code ?? 'Not yet allotted a DPC ID' }}</dd>

        <dt class="col-sm-3">{{ __('ui.promotion') }}</dt>
        <dd class="col-sm-9">
            {{ $proposal->promotionFromPost?->name ?? '—' }} &rarr; {{ $proposal->promotionToPost?->name ?? '—' }}
        </dd>

        <dt class="col-sm-3">{{ __('ui.committee_sitting') }}</dt>
        <dd class="col-sm-9 mb-0">{{ $sitting_date?->format('d-m-Y') ?? 'Not scheduled yet' }}</dd>
    </dl>

    <p class="small fw-semibold">
        {{ __('ui.data_being_considered_as_provided_by_your_depart') }}
    </p>

    @include('self-inspection.partials.record', [
        'row' => $row, 'years' => $years,
        'cr' => $cr, 'loyalty' => $loyalty, 'property' => $property,
    ])

    <div class="mt-4">
        @include('self-inspection.partials.notice', [
            'deadlineText' => $presenter->formattedDeadline($objection_deadline),
            'deadlinePassed' => $objection_deadline_passed,
        ])
    </div>

    {{-- Stated on the paper, not only on the screen. A printed page circulates
         further than the session that produced it, and somebody will eventually
         read this one as though it were a decision. --}}
    <p class="small text-body-secondary mt-4 mb-0">
{!! __('selfinspection.print.disclaimer') !!}
    </p>

    <p class="d-print-none mt-4 mb-0">
        {{-- `data-print` and a delegated listener, not `onclick` — an enforced
             Content-Security-Policy refuses inline handlers. --}}
        <button type="button" class="btn btn-primary btn-sm" data-print>{{ __('selfinspection.print.print_this_page') }}</button>
    </p>
</div>

{{-- External, so this screen satisfies `script-src 'self'` even when the policy is
     enforced. See TD-29 for the screens that do not yet. --}}
<script src="@versioned('js/self-inspection-print.js')"></script>
</body>
</html>
