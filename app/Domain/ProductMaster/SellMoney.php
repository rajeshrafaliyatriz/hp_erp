<?php

namespace App\Domain\ProductMaster;

use App\Domain\Money\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money rules of a Sell section (a catalogue row), in ONE place for both doors that write it: the one-record writer (ProductWriter) and the CRM Products form
 * (CrmProductController, its create, update and import). S16, 25 Nov 2026; contract Docs/agentic-crm/slices/S16-PRICING-CONTRACT.md section 4.
 *
 *   currency    a non-empty value must be a catalogue code (config/currencies.php; any case, stored upper case) or exactly what the row already holds, so an old free-text row can still
 *               be saved: else "Choose a currency from the list." on `currency`
 *   default     a NEW row with no currency gets the organisation's default (Currencies::defaultFor: `org.currency` when it is in the list, else INR)
 *   tax rate    a rate tied to a currency fits a Sub-product priced in that currency only, whichever of the two a request changes:
 *               "This tax rate is for <CUR>. Choose a rate for <CUR>, or one that is not tied to a currency." on `taxRateId`; a rate with no currency fits any
 * A write that touches neither the currency nor the tax rate is not judged: a row with an old mismatch can still be renamed. The judgement is of what the write would LEAVE BEHIND.
 */
final class SellMoney
{
    /**
     * @param  array<string, mixed>  $fields  the Sell fields of the request by request key (`currency`, `taxRateId`, ...): only the keys the request carries
     * @param  ?object  $stored  the catalogue row as stored (a row about to be made: null)
     * @param  ?object  $rate  the tax rate the request names, when the caller has already read it (`id`, and `currency` where the host has that column); null: it is read here when needed
     * @return array{0: array<string, mixed>, 1: ?array{0: string, 1: string}} the fields to write (the currency as it is stored), and the problem [field, sentence] or null
     */
    public static function judge(int $tenant, array $fields, ?object $stored, ?object $rate = null): array
    {
        if ($fields === [] && $stored !== null) {
            return [$fields, null];
        }
        $storedCurrency = $stored !== null && ($stored->currency ?? null) !== null ? (string) $stored->currency : null;
        $hasCurrency = array_key_exists('currency', $fields);
        $hasRate = array_key_exists('taxRateId', $fields);
        if ($hasCurrency) {
            if (($error = CatalogueFields::currencyError($fields['currency'], $storedCurrency)) !== null) {
                return [$fields, ['currency', $error]];
            }
            $fields['currency'] = CatalogueFields::currencyToStore($fields['currency'], $storedCurrency);
        }
        $creating = $stored === null;
        if ($creating && ($fields['currency'] ?? null) === null) {
            $fields['currency'] = Currencies::defaultFor($tenant);
        }

        if ($hasCurrency || $hasRate || $creating) {
            $currency = array_key_exists('currency', $fields) ? $fields['currency'] : $storedCurrency;
            if ($rate === null) {
                $id = $hasRate ? $fields['taxRateId'] : ($stored->tax_rate_id ?? null);
                $rate = $id !== null && $id !== '' ? self::rate($tenant, (int) $id) : null;
            }
            if ($rate !== null && ($error = CatalogueFields::taxCurrencyError($rate->currency ?? null, $currency)) !== null) {
                return [$fields, ['taxRateId', $error]];
            }
        }

        return [$fields, null];
    }

    /** One of the organisation's own tax rates by id (`id`, and `currency` where the host has that column); null when there is none. */
    public static function rate(int $tenant, int $id): ?object
    {
        if (! Schema::hasTable('crm_tax_rates')) {
            return null;
        }
        $columns = Schema::hasColumn('crm_tax_rates', 'currency') ? ['id', 'currency'] : ['id'];

        return DB::table('crm_tax_rates')->where('id', $id)->where('sub_institute_id', $tenant)->first($columns);
    }
}
