<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 36px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2937; }
    table { border-collapse: collapse; width: 100%; }
    .letterhead td { vertical-align: top; }
    .company-name { font-size: 16px; font-weight: bold; color: #111827; }
    .doc-title { font-size: 22px; font-weight: bold; color: #4f46e5; text-align: right; letter-spacing: 1px; }
    .doc-meta { text-align: right; color: #6b7280; font-size: 10px; line-height: 1.5; }
    .doc-meta b { color: #1f2937; }
    .divider { border-bottom: 2px solid #4f46e5; margin: 10px 0 14px 0; height: 1px; font-size: 0; }
    .parties td { vertical-align: top; width: 50%; padding-right: 12px; }
    .party-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; padding-bottom: 3px; }
    .party-name { font-size: 12px; font-weight: bold; color: #111827; }
    .party-line { color: #374151; line-height: 1.5; }
    .section-gap { height: 14px; font-size: 0; }
    .items th { background-color: #4f46e5; color: #ffffff; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; padding: 7px 8px; text-align: left; }
    .items td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10.5px; }
    .items tr:nth-child(even) td { background-color: #f9fafb; }
    .num { text-align: right; }
    .totals-wrap { width: 100%; }
    .totals-wrap td { vertical-align: top; }
    .terms { width: 60%; padding-right: 16px; }
    .terms .party-label { margin-top: 10px; }
    .terms-body { color: #374151; line-height: 1.6; white-space: pre-wrap; }
    .totals-table { width: 100%; }
    .totals-table td { padding: 4px 0; font-size: 11px; }
    .totals-table .label { color: #6b7280; text-align: right; padding-right: 14px; }
    .totals-table .value { text-align: right; width: 90px; }
    .grand-row td { border-top: 2px solid #4f46e5; padding-top: 8px; font-size: 13px; font-weight: bold; color: #111827; }
    .stage-badge { display: inline; background-color: #eef2ff; color: #4338ca; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; }
    .footer-note { margin-top: 24px; text-align: center; color: #9ca3af; font-size: 9px; }
</style>
</head>
<body>

    <table class="letterhead">
        <tr>
            <td style="width:55%;">
                <div class="company-name">{{ $schoolName ?: 'Our Organization' }}</div>
            </td>
            <td style="width:45%;">
                <div class="doc-title">QUOTATION</div>
                <div class="doc-meta">
                    <b>{{ $quote->quote_no }}</b><br>
                    Date: {{ optional($quote->created_at ? \Carbon\Carbon::parse($quote->created_at) : null)->format('d M Y') ?? '-' }}<br>
                    Valid Till: {{ $quote->valid_till ? \Carbon\Carbon::parse($quote->valid_till)->format('d M Y') : '-' }}
                </div>
            </td>
        </tr>
    </table>
    <div class="divider"></div>

    <table class="parties">
        <tr>
            <td>
                <div class="party-label">Bill To</div>
                <div class="party-name">{{ $quote->organization_name ?: ($quote->contact_name ?: '-') }}</div>
                @if($quote->contact_name && $quote->organization_name)
                    <div class="party-line">Attn: {{ $quote->contact_name }}</div>
                @endif
                @if($quote->billing_street)<div class="party-line">{{ $quote->billing_street }}</div>@endif
                @if($quote->billing_city || $quote->billing_state || $quote->billing_code)
                    <div class="party-line">{{ trim(implode(', ', array_filter([$quote->billing_city, $quote->billing_state, $quote->billing_code]))) }}</div>
                @endif
                @if($quote->billing_country)<div class="party-line">{{ $quote->billing_country }}</div>@endif
            </td>
            <td>
                <div class="party-label">Ship To</div>
                @if($quote->shipping_street || $quote->shipping_city)
                    <div class="party-line">{{ $quote->shipping_street }}</div>
                    <div class="party-line">{{ trim(implode(', ', array_filter([$quote->shipping_city, $quote->shipping_state, $quote->shipping_code]))) }}</div>
                    @if($quote->shipping_country)<div class="party-line">{{ $quote->shipping_country }}</div>@endif
                @else
                    <div class="party-line">Same as billing address</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="section-gap"></div>
    <table class="parties">
        <tr>
            <td style="width:25%;"><div class="party-label">Subject</div><div class="party-line">{{ $quote->subject }}</div></td>
            <td style="width:25%;"><div class="party-label">Stage</div><div class="party-line"><span class="stage-badge">{{ $quote->quote_stage ?: '-' }}</span></div></td>
            <td style="width:25%;"><div class="party-label">Prepared By</div><div class="party-line">{{ $assignedToName ?: '-' }}</div></td>
            <td style="width:25%;"><div class="party-label">Currency</div><div class="party-line">{{ $quote->currency ?: '-' }}</div></td>
        </tr>
    </table>

    <div class="section-gap"></div>
    <table class="items">
        <thead>
        <tr>
            <th style="width:4%;">#</th>
            <th style="width:38%;">Description</th>
            <th style="width:10%;" class="num">Qty</th>
            <th style="width:14%;" class="num">Unit Price</th>
            <th style="width:12%;" class="num">Discount</th>
            <th style="width:10%;" class="num">Tax</th>
            <th style="width:14%;" class="num">Amount</th>
        </tr>
        </thead>
        <tbody>
        @forelse($lineItems as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->description ?: '-' }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="num">
                    @if($item->discount_percent !== null)
                        {{ rtrim(rtrim(number_format((float) $item->discount_percent, 2), '0'), '.') }}%
                    @elseif((float) $item->discount_amount > 0)
                        {{ number_format((float) $item->discount_amount, 2) }}
                    @else
                        -
                    @endif
                </td>
                <td class="num">{{ $item->tax_percent_snapshot !== null ? rtrim(rtrim(number_format((float) $item->tax_percent_snapshot, 2), '0'), '.') . '%' : '-' }}</td>
                <td class="num">{{ number_format((float) $item->line_total, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:#9ca3af; padding:16px;">No line items.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-gap"></div>
    <table class="totals-wrap">
        <tr>
            <td class="terms">
                @if($quote->terms_conditions)
                    <div class="party-label">Terms &amp; Conditions</div>
                    <div class="terms-body">{{ $quote->terms_conditions }}</div>
                @endif
                @if($quote->description)
                    <div class="party-label">Notes</div>
                    <div class="terms-body">{{ $quote->description }}</div>
                @endif
            </td>
            <td>
                <table class="totals-table">
                    <tr><td class="label">Subtotal</td><td class="value">{{ number_format((float) $quote->subtotal, 2) }}</td></tr>
                    @if((float) $quote->discount_amount > 0)
                        <tr><td class="label">Discount</td><td class="value">-{{ number_format((float) $quote->discount_amount, 2) }}</td></tr>
                    @endif
                    @if((float) $quote->tax_total > 0)
                        <tr><td class="label">Tax</td><td class="value">{{ number_format((float) $quote->tax_total, 2) }}</td></tr>
                    @endif
                    @if((float) $quote->shipping_handling_amount > 0)
                        <tr><td class="label">Shipping &amp; Handling</td><td class="value">{{ number_format((float) $quote->shipping_handling_amount, 2) }}</td></tr>
                    @endif
                    @if((float) $quote->adjustment != 0)
                        <tr><td class="label">Adjustment</td><td class="value">{{ number_format((float) $quote->adjustment, 2) }}</td></tr>
                    @endif
                    <tr class="grand-row"><td class="label">Total</td><td class="value">{{ $quote->currency }} {{ number_format((float) $quote->total, 2) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="footer-note">This is a system-generated quotation from {{ $schoolName ?: 'our organization' }}.</div>

</body>
</html>
