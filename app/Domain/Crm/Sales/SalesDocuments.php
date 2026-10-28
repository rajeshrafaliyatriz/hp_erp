<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two conversions of the order-to-cash chain - Quote -> Sales Order and Sales Order -> Invoice - moved here UNCHANGED from
 * CrmSalesOrderController::fromQuote() and CrmInvoiceController::fromSalesOrder(), so a person pressing the button and the
 * automation (after an administrator approves) run the very same code. The controllers keep their own validation and identity
 * handling and call these.
 *
 * Nothing here records a payment or changes a financial status: a new document starts with whatever stage the tenant's own
 * process gives it (none), and payments only ever come from CrmInvoiceController::recordPayment() - a person's ledger entry.
 */
final class SalesDocuments
{
    /** Quote -> Sales Order, copying the quote's header and its frozen line items. @return int the new crm_sales_orders.id */
    public static function salesOrderFromQuote(object $quote, int $tenant, int $actorId, ?int $assignedTo = null, ?string $subject = null): int
    {
        $id = DB::transaction(function () use ($quote, $tenant, $actorId, $assignedTo, $subject) {
            $soId = DB::table('crm_sales_orders')->insertGetId([
                'so_no' => 'SO-'.Str::upper(Str::random(8)),
                'subject' => $subject !== null && $subject !== '' ? $subject : $quote->subject,
                'organization_id' => $quote->organization_id,
                'contact_id' => $quote->contact_id,
                'opportunity_id' => $quote->opportunity_id,
                'quote_id' => $quote->id,
                'currency' => $quote->currency,
                'billing_street' => $quote->billing_street,
                'billing_city' => $quote->billing_city,
                'billing_state' => $quote->billing_state,
                'billing_code' => $quote->billing_code,
                'billing_country' => $quote->billing_country,
                'billing_po_box' => $quote->billing_po_box,
                'shipping_street' => $quote->shipping_street,
                'shipping_city' => $quote->shipping_city,
                'shipping_state' => $quote->shipping_state,
                'shipping_code' => $quote->shipping_code,
                'shipping_country' => $quote->shipping_country,
                'shipping_po_box' => $quote->shipping_po_box,
                'terms_conditions' => $quote->terms_conditions,
                'shipping_handling_amount' => 0,
                'adjustment' => 0,
                'assigned_to' => $assignedTo ?? $actorId,
                'sub_institute_id' => $tenant,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $quoteLineItems = DB::table('crm_quote_line_items')->where('quote_id', $quote->id)->orderBy('sequence_no')->get();

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $rows = [];

            foreach ($quoteLineItems as $item) {
                $lineGross = (float) $item->quantity * (float) $item->unit_price;
                $discountAmount = (float) ($item->discount_amount ?? 0);
                $subtotal += $lineGross;
                $discountTotal += $discountAmount;
                $taxTotal += (float) $item->line_total - max(0, $lineGross - $discountAmount);

                $rows[] = [
                    'sales_order_id' => $soId,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_percent' => $item->discount_percent,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate_id' => $item->tax_rate_id,
                    'tax_name_snapshot' => $item->tax_name_snapshot,
                    'tax_percent_snapshot' => $item->tax_percent_snapshot,
                    'line_total' => $item->line_total,
                    'sequence_no' => $item->sequence_no,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('crm_sales_order_line_items')->insert($rows);

                DB::table('crm_sales_orders')->where('id', $soId)->update([
                    'subtotal' => round($subtotal, 2),
                    'discount_amount' => round($discountTotal, 2),
                    'tax_total' => round($taxTotal, 2),
                ]);
            }

            self::recomputeTotal('crm_sales_orders', $soId);

            return $soId;
        });
        GtmAudit::record('crm.sales_order.created', $tenant, 'crm_sales_orders', (int) $id, $actorId, ['quote_id' => (int) $quote->id, 'opportunity_id' => $quote->opportunity_id ? (int) $quote->opportunity_id : null], null, "crm.sales_order.created:{$tenant}:{$id}");

        return (int) $id;
    }

    /** Sales Order -> Invoice, copying the order's header and a fresh copy of its line items. @return int the new crm_invoices.id */
    public static function invoiceFromSalesOrder(object $salesOrder, int $tenant, int $actorId, ?int $assignedTo = null): int
    {
        $salesOrderId = (int) $salesOrder->id;
        $id = DB::transaction(function () use ($salesOrder, $salesOrderId, $tenant, $actorId, $assignedTo) {
            $invoiceData = [
                'subject' => $salesOrder->subject,
                'sales_order_id' => $salesOrder->id,
                'organization_id' => $salesOrder->organization_id,
                'contact_id' => $salesOrder->contact_id,
                'currency' => $salesOrder->currency,
                'billing_street' => $salesOrder->billing_street,
                'billing_city' => $salesOrder->billing_city,
                'billing_state' => $salesOrder->billing_state,
                'billing_code' => $salesOrder->billing_code,
                'billing_country' => $salesOrder->billing_country,
                'billing_po_box' => $salesOrder->billing_po_box,
                'shipping_street' => $salesOrder->shipping_street,
                'shipping_city' => $salesOrder->shipping_city,
                'shipping_state' => $salesOrder->shipping_state,
                'shipping_code' => $salesOrder->shipping_code,
                'shipping_country' => $salesOrder->shipping_country,
                'shipping_po_box' => $salesOrder->shipping_po_box,
                'terms_conditions' => $salesOrder->terms_conditions,
                'invoice_date' => now()->toDateString(),
                'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
                'assigned_to' => $assignedTo ?? $actorId,
                'sub_institute_id' => $tenant,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $invoiceId = DB::table('crm_invoices')->insertGetId($invoiceData);

            $sourceLineItems = DB::table('crm_sales_order_line_items')
                ->where('sales_order_id', $salesOrderId)
                ->orderBy('sequence_no')
                ->get();

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxTotal = 0.0;
            $rows = [];

            foreach ($sourceLineItems as $index => $source) {
                $quantity = (float) $source->quantity;
                $unitPrice = (float) $source->unit_price;
                $lineGross = $quantity * $unitPrice;

                $discountAmount = $source->discount_percent !== null
                    ? round($lineGross * ((float) $source->discount_percent) / 100, 2)
                    : (float) ($source->discount_amount ?? 0);

                $afterDiscount = max(0, $lineGross - $discountAmount);
                $taxPercent = $source->tax_percent_snapshot !== null ? (float) $source->tax_percent_snapshot : 0.0;
                $lineTax = round($afterDiscount * $taxPercent / 100, 2);
                $lineTotal = round($afterDiscount + $lineTax, 2);

                $subtotal += $lineGross;
                $discountTotal += $discountAmount;
                $taxTotal += $lineTax;

                $rows[] = [
                    'invoice_id' => $invoiceId,
                    'product_id' => $source->product_id,
                    'description' => $source->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $source->discount_percent,
                    'discount_amount' => $discountAmount,
                    'tax_rate_id' => $source->tax_rate_id,
                    'tax_name_snapshot' => $source->tax_name_snapshot,
                    'tax_percent_snapshot' => $source->tax_percent_snapshot,
                    'line_total' => $lineTotal,
                    'sequence_no' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('crm_invoice_line_items')->insert($rows);
            }

            DB::table('crm_invoices')->where('id', $invoiceId)->update([
                'subtotal' => round($subtotal, 2),
                'discount_amount' => round($discountTotal, 2),
                'tax_total' => round($taxTotal, 2),
            ]);

            self::recomputeTotal('crm_invoices', $invoiceId);

            return $invoiceId;
        });
        GtmAudit::record('crm.invoice.created', $tenant, 'crm_invoices', (int) $id, $actorId, ['sales_order_id' => $salesOrderId], null, "crm.invoice.created:{$tenant}:{$id}");

        return (int) $id;
    }

    /** total = subtotal - discount + tax + shipping/handling + adjustment: the formula every CRM document uses. */
    public static function recomputeTotal(string $table, int $id): void
    {
        $row = DB::table($table)->where('id', $id)
            ->first(['subtotal', 'discount_amount', 'tax_total', 'shipping_handling_amount', 'adjustment']);

        if (! $row) {
            return;
        }

        $total = round(
            (float) $row->subtotal - (float) $row->discount_amount + (float) $row->tax_total
                + (float) $row->shipping_handling_amount + (float) $row->adjustment,
            2
        );

        DB::table($table)->where('id', $id)->update(['total' => $total]);
    }
}
