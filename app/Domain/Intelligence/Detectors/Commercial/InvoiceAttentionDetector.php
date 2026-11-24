<?php

namespace App\Domain\Intelligence\Detectors\Commercial;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Query\Builder;

/**
 * Invoices that are past their due date and not fully paid. Needs `view` on /module/crm/sales/invoices.
 *
 * The overdue rule is the product's own (App\Domain\Crm\Sales\InvoiceSweep, verified): due_date set AND due_date < today AND total > 0 AND
 * (total - amount_paid) > 0.004. Like the sweep it does NOT filter on `invoice_stage` (the stage vocabulary is unverified, so a cancelled or draft invoice
 * is NOT excluded: the evidence states this). Ageing: more than `invoice_overdue_days` (default 30, P-12 family) past due is `high`.
 *
 * Money: amounts and balances are shown only because the caller already holds view on the invoices screen, and they are ALWAYS PER CURRENCY (an invoice with
 * no currency is its own "(no currency)" line); currencies are never added together. Needs `invoices_min` invoices with a due date (P-12).
 */
final class InvoiceAttentionDetector extends CommercialDetector
{
    private const LINKS = [CommercialAccess::LINK_CRM_INVOICES];

    public function id(): string
    {
        return 'commercial.invoice_attention';
    }

    public function title(): string
    {
        return 'Overdue invoices';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_CRM_INVOICES];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(self::LINKS);
    }

    /**
     * THE overdue rule, shared with the cross-functional detectors so there is one definition: due date before today, a positive total and a balance above
     * 0.004. `$prefix` is a table alias including its dot (for example `i.`). A NULL due date is never overdue (the comparison is false). No stage filter.
     */
    public static function applyOverdueRule(Builder $query, string $today, string $prefix = ''): Builder
    {
        return $query->where($prefix . 'due_date', '<', $today)->where($prefix . 'total', '>', 0)->whereRaw("({$prefix}total - {$prefix}amount_paid) > 0.004");
    }

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $dated = fn () => $this->db->table('crm_invoices')->where('sub_institute_id', $caller->tenantId)->whereNull('deleted_at')->whereNotNull('due_date');
        $withDue = (int) $dated()->count();
        $coverage = Coverage::of($withDue, null, 'invoices that have a due date', $this->thresholds->int('invoices_min'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $clock->asOf());
        }

        $today = $clock->today();
        $overdue = fn () => self::applyOverdueRule($dated(), $today);
        $count = (int) $overdue()->count();
        if ($count === 0) {
            return DetectorResult::ok($this, [], $coverage, $clock->asOf());
        }

        $ageDays = $this->thresholds->int('invoice_overdue_days');
        $older = (int) $overdue()->where('due_date', '<', $clock->daysAgo($ageDays))->count();
        $noOwner = (int) $overdue()->whereNull('assigned_to')->count();

        $evidence = [
            Evidence::metric('Invoices with a due date', $withDue, 'invoices', 'CRM invoices'),
            Evidence::metric('Overdue and not fully paid', $count, 'invoices'),
            Evidence::metric("Overdue by more than {$ageDays} days", $older, 'invoices'),
            Evidence::metric('Overdue with no owner', $noOwner, 'invoices'),
        ];
        $perCurrency = $overdue()->selectRaw('currency as c, COUNT(*) as n, SUM(total - amount_paid) as balance')->groupBy('currency')->orderBy('currency')->get();
        foreach ($perCurrency as $row) {
            $label = trim((string) $row->c) === '' ? '(no currency)' : strtoupper(trim((string) $row->c));
            $evidence[] = Evidence::metric("Overdue invoices in {$label}", (int) $row->n, 'invoices');
            $evidence[] = Evidence::metric("Overdue balance in {$label}", round((float) $row->balance, 2), trim((string) $row->c) === '' ? null : strtoupper(trim((string) $row->c)));
        }
        $evidence[] = Evidence::metric('Invoice stage filter (the stage vocabulary is unverified, so cancelled or draft invoices are not excluded)', 'not applied');
        $ids = $overdue()->orderBy('due_date')->orderBy('id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('id')->all();
        array_push($evidence, ...$this->entities($caller, 'Overdue invoice', 'crm_invoice', $ids));

        return DetectorResult::ok($this, [new Insight(
            $this->key($caller, 'overdue', $today), $this->id(), $this->domain(), 'invoices', $older > 0 ? 'high' : 'attention',
            sprintf('%d invoice(s) are overdue', $count),
            sprintf('%d invoice(s) are past their due date with a balance outstanding; %d of them by more than %d days.', $count, $older, $ageDays),
            'These invoices are past their due date with a balance outstanding; the ageing figure shows how long the oldest has been outstanding.',
            $evidence, $coverage, $clock->asOf(), 'Follow up with the customers starting with the oldest, and give each invoice an owner.', '/module/crm/sales/invoices', 'rule_based',
            $this->audience(), $this->surfaces(), null,
        )], $coverage, $clock->asOf());
    }
}
