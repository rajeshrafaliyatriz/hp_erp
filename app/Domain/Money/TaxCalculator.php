<?php

namespace App\Domain\Money;

/**
 * The ONE tax rule (S16 contract section 3), the same on both sides: tax is EXCLUSIVE (the price, and the tax on top of it), worked in whole minor units so no float ever decides a
 * rounding.
 *
 *   minor    = round half up (price * 10^decimals)
 *   pctMilli = round half up (percentage * 1000)                          a rate has three decimals (decimal(7,3))
 *   taxMinor = floor ((minor * pctMilli + 50000) / 100000)                half up on the tax, per price
 *   total    = minor + taxMinor
 *
 * lib/money/tax.ts `priceWithTax` is the same rule and is proven with the same vectors (tests/Unit/Money/TaxCalculatorTest.php). A null or missing percentage is tax 0.
 * The multiplication is split so it cannot pass PHP_INT_MAX: a price column holds up to 9999999999999.99 (1e15 minor units) and a rate up to 100% (100000 milli), and their product is 1e20.
 */
final class TaxCalculator
{
    /**
     * @param  string|int|float  $price  a price in major units ("999.99" and 999.99 are the same price)
     * @param  string|int|float|null  $percentage  the rate in percent (18, "7.5"); null is no tax
     * @return array{subtotal: float, tax: float, total: float} each a JSON number rounded to `$decimals`
     */
    public static function tax(string|int|float $price, string|int|float|null $percentage, int $decimals): array
    {
        $decimals = max(0, min(6, $decimals));
        $minor = self::toMinor($price, $decimals);
        $taxMinor = self::taxMinor($minor, self::percentMilli($percentage));

        return [
            'subtotal' => self::fromMinor($minor, $decimals),
            'tax' => self::fromMinor($taxMinor, $decimals),
            'total' => self::fromMinor($minor + $taxMinor, $decimals),
        ];
    }

    /** A price in major units as whole minor units, half up (away from zero for a negative one, so a credit mirrors its charge). */
    public static function toMinor(string|int|float $amount, int $decimals): int
    {
        return self::scaled($amount, $decimals);
    }

    /** A rate in percent as thousandths of a percent, half up. null is 0. */
    public static function percentMilli(string|int|float|null $percentage): int
    {
        return $percentage === null || $percentage === '' ? 0 : self::scaled($percentage, 3);
    }

    /** The tax on a price already in minor units: floor((minor * pctMilli + 50000) / 100000), computed so that the product is never formed whole. */
    public static function taxMinor(int $minor, int $pctMilli): int
    {
        $sign = $minor < 0 ? -1 : 1;
        $abs = abs($minor);
        $quotient = intdiv($abs, 100000);
        $rest = $abs % 100000;
        $tax = $quotient * $pctMilli + intdiv($rest * $pctMilli + 50000, 100000);

        return $sign * $tax;
    }

    /** Minor units back to a number of major units rounded to `$decimals` (what a JSON answer carries). */
    public static function fromMinor(int $minor, int $decimals): float
    {
        return $decimals === 0 ? (float) $minor : round($minor / (10 ** $decimals), $decimals);
    }

    /** value * 10^scale as an integer, half up, read from the DECIMAL TEXT of the value (a float is first written with ten digits, which is where 1.005 stays 1.005). */
    private static function scaled(string|int|float $value, int $scale): int
    {
        $text = is_float($value) ? sprintf('%.10F', $value) : trim((string) $value);
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException('Not a number: ' . $text);
        }
        $negative = $m[1] === '-';
        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = $m[3] ?? '';
        $kept = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $next = strlen($fraction) > $scale ? (int) $fraction[$scale] : 0;
        $minor = (int) ltrim($whole . $kept, '0') + ($next >= 5 ? 1 : 0);

        return $negative ? -$minor : $minor;
    }
}
