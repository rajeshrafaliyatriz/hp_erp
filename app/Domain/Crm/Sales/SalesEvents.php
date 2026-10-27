<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * What happened to a Quote, Sales Order or Invoice, said once so the workflow engine (and the audit log) can react. Recording
 * never fails the action it describes (GtmAudit logs instead). A stage change is only announced when the stage really changed.
 */
final class SalesEvents
{
    private const STAGE = ['quote' => ['crm.quote.stage_changed', 'crm_quotes'], 'sales_order' => ['crm.sales_order.stage_changed', 'crm_sales_orders'], 'invoice' => ['crm.invoice.stage_changed', 'crm_invoices']];

    /** @param array<string, mixed>|null $meta ['source' => 'workflow'] for a change a workflow made (the engine then ignores it) */
    public static function stageChanged(string $kind, int $tenant, int $id, ?string $from, ?string $to, ?int $actorId, ?array $meta = null): void
    {
        if ($to === null || (string) $from === (string) $to) {
            return;
        }
        [$type, $entity] = self::STAGE[$kind];
        GtmAudit::record($type, $tenant, $entity, $id, $actorId, ['from' => $from, 'to' => $to], $meta, "{$type}:{$tenant}:{$id}:".now()->format('YmdHis').':'.substr(md5((string) $from.'>'.$to), 0, 6));
    }

    public static function quoteCreated(int $tenant, int $id, ?int $actorId, ?int $opportunityId): void
    {
        GtmAudit::record('crm.quote.created', $tenant, 'crm_quotes', $id, $actorId, ['opportunity_id' => $opportunityId], null, "crm.quote.created:{$tenant}:{$id}");
    }

    public static function salesOrderCreated(int $tenant, int $id, ?int $actorId, ?int $quoteId, ?int $opportunityId): void
    {
        GtmAudit::record('crm.sales_order.created', $tenant, 'crm_sales_orders', $id, $actorId, ['quote_id' => $quoteId, 'opportunity_id' => $opportunityId], null, "crm.sales_order.created:{$tenant}:{$id}");
    }

    public static function invoiceCreated(int $tenant, int $id, ?int $actorId, ?int $salesOrderId): void
    {
        GtmAudit::record('crm.invoice.created', $tenant, 'crm_invoices', $id, $actorId, ['sales_order_id' => $salesOrderId], null, "crm.invoice.created:{$tenant}:{$id}");
    }

    /** After a payment was recorded by a person: announce "paid" the moment the balance reaches zero (once per invoice). */
    public static function paymentRecorded(int $tenant, int $invoiceId, ?int $actorId): void
    {
        $inv = DB::table('crm_invoices')->where('sub_institute_id', $tenant)->find($invoiceId, ['id', 'invoice_no', 'total', 'amount_paid', 'assigned_to', 'organization_id']);
        if ($inv && (float) $inv->total > 0 && round((float) $inv->total - (float) $inv->amount_paid, 2) <= 0.004) {
            GtmAudit::record('crm.invoice.paid', $tenant, 'crm_invoices', $invoiceId, $actorId, ['invoice_no' => $inv->invoice_no, 'total' => (float) $inv->total, 'organization_id' => $inv->organization_id ? (int) $inv->organization_id : null], null, "crm.invoice.paid:{$tenant}:{$invoiceId}");
        }
    }
}
