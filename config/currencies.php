<?php

/**
 * The currency catalogue of the Product Portfolio and the CRM documents (S16, 25 Nov 2026; contract Docs/agentic-crm/slices/S16-PRICING-CONTRACT.md section 2).
 *
 * The SAME twelve currencies, in the same order, as lib/money/currencies.ts in g2gv0: a Sub-product is priced in one of these, a tax rate may be tied to one, and a
 * document shows the symbol of its own currency. `decimals` is how many digits the currency keeps after the point (the yen keeps none): every amount in an answer is rounded to it.
 * A code outside this list is LEGACY (typed by hand before the list existed): it is still read and shown as typed, and never "fixed" silently.
 *
 * Read it through App\Domain\Money\Currencies, not by config() key, so the lookup rules (upper case, legacy) live in one place.
 */
return [
    'currencies' => [
        ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2, 'country' => 'IN'],
        ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'country' => 'US'],
        ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'country' => 'EU'],
        ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'decimals' => 2, 'country' => 'GB'],
        ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimals' => 2, 'country' => 'AE'],
        ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimals' => 2, 'country' => 'SG'],
        ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2, 'country' => 'AU'],
        ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimals' => 2, 'country' => 'CA'],
        ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0, 'country' => 'JP'],
        ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'country' => 'CH'],
        ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'decimals' => 2, 'country' => 'SA'],
        ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'decimals' => 2, 'country' => 'ZA'],
    ],

    /** The currency a new Sub-product gets when the organisation's own setting (`org.currency`) is not in the list. */
    'fallback' => 'INR',
];
