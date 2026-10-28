<?php

namespace App\Domain\Crm\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prepares a DRAFT Quote from an Opportunity - only when everything it needs is a recorded fact, never a guess.
 *
 * An Opportunity's products are an "unpriced interest tag" (crm_opportunity_products has a quantity and no price), so this
 * builder prices each line ONLY from the product's own list price and tax rate on the Products screen. It refuses - with the
 * reason, so the owner can fix it - when:
 *   the Opportunity is closed, has no Organization, or has no products;
 *   a product is inactive, removed, or has no list price;
 *   the products are priced in different currencies (or the Opportunity names a currency they do not match);
 *   the Opportunity already has a live Quote (anything not Rejected or Expired) - so a retry never doubles it.
 * The line arithmetic is exactly CrmQuoteController::saveLineItems()'s (gross, discount, tax by rate, rounded), and a test
 * compares the two. The Quote is created at stage "Draft" with no discount; a person still reviews and sends it.
 */
final class QuoteBuilder
{
    /** @return array{ok: bool, quote_id?: int, reason?: string} */
    public static function fromOpportunity(object $opp, int $tenant, int $actorId, ?int $assignedTo): array
    {
        if (! OpportunityEvents::isOpen($opp->sales_stage)) {
            return ['ok' => false, 'reason' => "the Opportunity is already {$opp->sales_stage}"];
        }
        if (! $opp->organization_id) {
            return ['ok' => false, 'reason' => 'the Opportunity has no Organization'];
        }
        $live = DB::table('crm_quotes')->where('sub_institute_id', $tenant)->where('opportunity_id', $opp->id)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('quote_stage')->orWhereNotIn('quote_stage', ['Rejected', 'Expired']))->exists();
        if ($live) {
            return ['ok' => false, 'reason' => 'the Opportunity already has a live Quote'];
        }
        $items = DB::table('crm_opportunity_products as op')->join('crm_products as p', 'p.id', '=', 'op.product_id')
            ->where('op.opportunity_id', $opp->id)->where('p.sub_institute_id', $tenant)->whereNull('p.deleted_at')
            ->orderBy('op.id')->get(['p.id as product_id', 'p.name', 'p.unit_price', 'p.currency', 'p.tax_rate_id', 'p.is_active', 'op.quantity']);
        if ($items->isEmpty()) {
            return ['ok' => false, 'reason' => 'the Opportunity has no products'];
        }
        $unusable = $items->filter(fn ($i) => ! $i->is_active || $i->unit_price === null)->pluck('name')->all();
        if ($unusable !== []) {
            return ['ok' => false, 'reason' => 'no usable list price for: '.implode(', ', $unusable)];
        }
        $currencies = $items->pluck('currency')->map(fn ($c) => $c === null || $c === '' ? null : strtoupper((string) $c))->unique()->values();
        if ($currencies->count() > 1) {
            return ['ok' => false, 'reason' => 'the products are priced in different currencies'];
        }
        $currency = $currencies->first();
        if ($opp->currency && $currency !== null && strtoupper((string) $opp->currency) !== $currency) {
            return ['ok' => false, 'reason' => "the Opportunity is in {$opp->currency} but the products are priced in {$currency}"];
        }
        $currency ??= $opp->currency;

        $rates = DB::table('crm_tax_rates')->where('sub_institute_id', $tenant)->get(['id', 'name', 'percentage'])->keyBy('id');
        $contactId = DB::table('crm_opportunity_contacts as oc')->join('crm_contacts as c', 'c.id', '=', 'oc.contact_id')->where('oc.opportunity_id', $opp->id)
            ->where('c.sub_institute_id', $tenant)->whereNull('c.deleted_at')->orderBy('oc.id')->value('c.id');

        $quoteId = DB::transaction(function () use ($opp, $tenant, $actorId, $assignedTo, $items, $rates, $currency, $contactId) {
            $id = (int) DB::table('crm_quotes')->insertGetId([
                'quote_no' => 'QT-'.Str::upper(Str::random(8)), 'subject' => mb_substr($opp->name.' - Quote', 0, 191), 'organization_id' => $opp->organization_id, 'contact_id' => $contactId,
                'opportunity_id' => $opp->id, 'quote_stage' => 'Draft', 'currency' => $currency, 'shipping_handling_amount' => 0, 'adjustment' => 0,
                'assigned_to' => $assignedTo ?? $opp->assigned_to ?? $actorId, 'sub_institute_id' => $tenant, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $subtotal = $discountTotal = $taxTotal = 0.0;
            $rows = [];
            foreach ($items->values() as $index => $i) {
                $quantity = (float) $i->quantity;
                $unitPrice = (float) $i->unit_price;
                $lineGross = $quantity * $unitPrice;
                $discountAmount = 0.0;
                $afterDiscount = max(0, $lineGross - $discountAmount);
                $rate = $i->tax_rate_id ? $rates->get((int) $i->tax_rate_id) : null;
                $taxPercent = $rate ? (float) $rate->percentage : 0.0;
                $lineTax = round($afterDiscount * $taxPercent / 100, 2);
                $lineTotal = round($afterDiscount + $lineTax, 2);
                $subtotal += $lineGross;
                $discountTotal += $discountAmount;
                $taxTotal += $lineTax;
                $rows[] = [
                    'quote_id' => $id, 'product_id' => $i->product_id, 'description' => mb_substr((string) $i->name, 0, 500), 'quantity' => $quantity, 'unit_price' => $unitPrice,
                    'discount_percent' => null, 'discount_amount' => $discountAmount, 'tax_rate_id' => $rate ? $rate->id : null, 'tax_name_snapshot' => $rate ? $rate->name : null,
                    'tax_percent_snapshot' => $rate ? $rate->percentage : null, 'line_total' => $lineTotal, 'sequence_no' => $index, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('crm_quote_line_items')->insert($rows);
            DB::table('crm_quotes')->where('id', $id)->update(['subtotal' => round($subtotal, 2), 'discount_amount' => round($discountTotal, 2), 'tax_total' => round($taxTotal, 2)]);
            SalesDocuments::recomputeTotal('crm_quotes', $id);

            return $id;
        });
        SalesEvents::quoteCreated($tenant, $quoteId, $actorId, (int) $opp->id);

        return ['ok' => true, 'quote_id' => $quoteId];
    }
}
