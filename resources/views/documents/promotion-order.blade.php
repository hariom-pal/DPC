{{-- Coder Instructions rule 4.3: the promotion order, rendered to PDF by dompdf. English only: dompdf does not shape Devanagari. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Promotion Order {{ $order->order_number }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #111; margin: 28px 36px; }
        .center { text-align: center; }
        h1 { font-size: 13pt; margin: 0; }
        h2 { font-size: 11.5pt; margin: 4px 0 0; font-weight: normal; }
        .meta { width: 100%; margin: 18px 0 12px; }
        .meta td { padding: 2px 0; }
        table.list { border-collapse: collapse; width: 100%; margin: 12px 0; }
        table.list th, table.list td { border: 1px solid #777; padding: 4px 6px; text-align: left; }
        table.list th { background: #eee; }
        p { line-height: 1.45; margin: 8px 0; }
        .sign { margin-top: 36px; text-align: right; }
        .ref { margin-top: 28px; font-size: 8pt; color: #444; border-top: 1px solid #999; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="center">
        <h1>Government of Chhattisgarh</h1>
        <h2>{{ $proposal->department?->name }}</h2>
        <h2><strong>ORDER</strong></h2>
    </div>

    <table class="meta">
        <tr>
            <td>Order No.: <strong>{{ $order->order_number }}</strong></td>
            <td style="text-align:right">Date: <strong>{{ $order->order_date->format('d-m-Y') }}</strong></td>
        </tr>
    </table>

    @if ($previous)
        <p><strong>This order supersedes Order No. {{ $previous->order_number }} dated {{ $previous->order_date->format('d-m-Y') }}.</strong></p>
    @endif

    <p>
        On the recommendation of the Departmental Promotion Committee (DPC {{ $proposal->dpc_code ?? $proposal->id }}, DPC year
        {{ $proposal->dpc_year }}) convened through the Chhattisgarh Public Service Commission, whose result was approved on
        {{ $proceeding?->approved_at?->format('d-m-Y') ?? '—' }}, the following officers holding the post of
        <strong>{{ $proposal->promotionFromPost?->name }}</strong> are hereby promoted to the post of
        <strong>{{ $proposal->promotionToPost?->name }}</strong>@if ($proposal->payScale) ({{ $proposal->payScale->label }})@endif.
    </p>

    <table class="list">
        <thead>
            <tr><th>S. No.</th><th>Name of the officer</th><th>Employee ID</th><th>Category</th></tr>
        </thead>
        <tbody>
            @foreach ($order->officers as $index => $officer)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $officer['name'] }}</td>
                    <td>{{ $officer['employee_number'] ?? '—' }}</td>
                    <td>{{ $officer['category'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>The seniority of the officers promoted by this order on the post of {{ $proposal->promotionToPost?->name }} shall count from
        the date of this order, {{ $order->order_date->format('d-m-Y') }}.</p>
    <p>The finding on any officer kept in a sealed cover is not acted upon by this order; the post concerned is held.</p>

    <div class="sign">
        <div>Digitally signed by</div>
        <div><strong>{{ $issuer->name }}</strong></div>
        <div>Administrative Head, {{ $proposal->department?->name }}</div>
    </div>

    <div class="ref">
        Order reference {{ $order->uuid }} · version {{ $order->version }} · DPC proposal {{ $proposal->uuid }} ·
        CGPSC proceeding {{ $proceeding?->uuid }} · issued {{ $order->issued_at->format('d-m-Y H:i') }}
    </div>
</body>
</html>
