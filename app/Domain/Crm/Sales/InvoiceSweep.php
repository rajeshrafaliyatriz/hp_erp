<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Facades\DB;

/**
 * The clock-driven half of invoice follow-up: find Invoices that still have a balance after their due date and say so ONCE A
 * WEEK per invoice - `crm.invoice.overdue`, a workflow trigger that also notifies the invoice's owner. Balance is
 * total - amount_paid (the ledger sum), the same figure the Invoice screen shows. An invoice with no due date, a zero total, or
 * nothing owing is never reported, and nothing here ever records a payment or changes an invoice.
 */
final class InvoiceSweep
{
    /**
     * @param  list<int>|null  $onlyIds  restrict the pass to these invoices (tests); null = every tenant
     * @return array{checked: int, overdue: int}
     */
    public static function run(int $limit = 500, ?array $onlyIds = null, ?int $tenant = null): array
    {
        $out = ['checked' => 0, 'overdue' => 0];
        $week = now()->format('o-\WW');
        $today = now()->toDateString();
        $rows = DB::table('crm_invoices')->whereNull('deleted_at')->whereNotNull('due_date')->where('due_date', '<', $today)->where('total', '>', 0)
            ->whereRaw('(total - amount_paid) > 0.004')
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))->when($tenant !== null, fn ($q) => $q->where('sub_institute_id', $tenant))->orderBy('id')->limit($limit)
            ->get(['id', 'invoice_no', 'subject', 'sub_institute_id', 'assigned_to', 'total', 'amount_paid', 'due_date', 'currency']);
        foreach ($rows as $i) {
            $out['checked']++;
            $id = GtmAudit::record('crm.invoice.overdue', (int) $i->sub_institute_id, 'crm_invoices', (int) $i->id, null, [
                'invoice_no' => $i->invoice_no, 'subject' => $i->subject, 'balance_due' => round((float) $i->total - (float) $i->amount_paid, 2), 'currency' => $i->currency,
                'due_date' => $i->due_date, 'days_overdue' => (int) \Illuminate\Support\Carbon::parse($i->due_date)->diffInDays(now()), 'assigned_to' => $i->assigned_to ? (int) $i->assigned_to : null,
            ], null, "crm.invoice.overdue:{$i->id}:{$i->due_date}:{$week}");
            $out['overdue'] += $id ? 1 : 0;
        }

        return $out;
    }
}
