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
    <title>Self-Inspection — {{ $row->name }}</title>
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
            <h1 class="h5 mb-1">Self-Inspection &amp; Assessment — Officer's own data</h1>
            <p class="small text-body-secondary mb-0">
                स्व-निरीक्षण · Chhattisgarh Public Service Commission — Online DPC
            </p>
        </div>
        <div class="text-end small text-body-secondary">
            Printed {{ $printed_at->format('d-m-Y H:i') }}
        </div>
    </div>

    <dl class="row small mb-4">
        <dt class="col-sm-3">Department</dt>
        <dd class="col-sm-9">{{ $department->name }}</dd>

        <dt class="col-sm-3">DPC</dt>
        <dd class="col-sm-9">{{ $proposal->dpc_code ?? 'Not yet allotted a DPC ID' }}</dd>

        <dt class="col-sm-3">Promotion</dt>
        <dd class="col-sm-9">
            {{ $proposal->postFromCadre?->name ?? '—' }} &rarr; {{ $proposal->postToCadre?->name ?? '—' }}
        </dd>

        <dt class="col-sm-3">Committee sitting</dt>
        <dd class="col-sm-9 mb-0">{{ $sitting_date?->format('d-m-Y') ?? 'Not scheduled yet' }}</dd>
    </dl>

    <p class="small fw-semibold">
        DATA BEING CONSIDERED AS PROVIDED BY YOUR DEPARTMENT FOR ONLINE DPC
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
        This is a copy of the data supplied by the department for consideration by the Departmental
        Promotion Committee. <strong>It is not a promotion order, a result, or a decision of any
        kind</strong>, and it carries no mark, remark or assessment. It is unsigned and is issued for
        the officer's own inspection.
    </p>

    <p class="d-print-none mt-4 mb-0">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print this page</button>
    </p>
</div>

<script>
    // Opened in a new tab from the Print button, so it offers the print dialog
    // straight away. Guarded because this view is also directly addressable.
    if (window.location.search.indexOf('noprint') === -1) {
        window.addEventListener('load', function () { window.print(); });
    }
</script>
</body>
</html>
