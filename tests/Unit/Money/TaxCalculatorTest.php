<?php

namespace Tests\Unit\Money;

use App\Domain\Money\TaxCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The tax rule of S16 (contract section 3), proven with the SHARED vectors: the same twelve lines are in g2gv0's lib/money/tax.test.ts, so the two sides cannot drift apart.
 * Tax is exclusive; minor units; half up on the tax of each price.
 */
class TaxCalculatorTest extends TestCase
{
    /** @return array<string, array{0: string|float|int, 1: string|float|int, 2: int, 3: float, 4: float}> price, percent, decimals, tax, total */
    public static function vectors(): array
    {
        return [
            '1000 at 18'       => [1000, 18, 2, 180.00, 1180.00],
            '999.99 at 5'      => [999.99, 5, 2, 50.00, 1049.99],
            'zero price'       => [0, 18, 2, 0.0, 0.0],
            'zero percent'     => [1500, 0, 2, 0.0, 1500.00],
            '1 at 12'          => [1, 12, 2, 0.12, 1.12],
            '0.05 at 5'        => [0.05, 5, 2, 0.00, 0.05],
            '0.10 at 5'        => [0.10, 5, 2, 0.01, 0.11],
            '2499 at 28'       => [2499, 28, 2, 699.72, 3198.72],
            '1000 at 18, yen'  => [1000, 18, 0, 180.0, 1180.0],
            '1050 at 5, yen'   => [1050, 5, 0, 53.0, 1103.0],
            '19.99 at 7.5'     => [19.99, 7.5, 2, 1.50, 21.49],
            '100 at 12.5'      => [100, 12.5, 2, 12.50, 112.50],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('vectors')]
    public function test_the_shared_vectors(string|float|int $price, string|float|int $percent, int $decimals, float $tax, float $total): void
    {
        $r = TaxCalculator::tax($price, $percent, $decimals);

        $this->assertSame($tax, $r['tax'], 'tax');
        $this->assertSame($total, $r['total'], 'total');
        $this->assertSame(round((float) $price, $decimals), $r['subtotal'], 'subtotal is the price itself');
    }

    public function test_a_price_and_a_rate_given_as_text_are_the_same_as_numbers(): void
    {
        $this->assertSame(TaxCalculator::tax(999.99, 5, 2), TaxCalculator::tax('999.99', '5.000', 2));
        $this->assertSame(TaxCalculator::tax(19.99, 7.5, 2), TaxCalculator::tax('19.990', '7.500', 2), 'the shape a decimal column answers with');
    }

    public function test_no_percentage_is_no_tax(): void
    {
        $this->assertSame(['subtotal' => 250.0, 'tax' => 0.0, 'total' => 250.0], TaxCalculator::tax(250, null, 2));
    }

    public function test_the_half_is_rounded_up_and_never_by_the_float_error_of_the_price(): void
    {
        // 1.005 is 1.00499999999999989 as a float: the price still counts as 1.005, which is 1.01 at two decimals.
        $this->assertSame(1.01, TaxCalculator::tax(1.005, 0, 2)['subtotal']);
        // 0.50 at 5% is 0.025: two and a half minor units, so three (half up), never two (the banker's rounding).
        $this->assertSame(0.03, TaxCalculator::tax('0.50', 5, 2)['tax']);
        $this->assertSame(0.01, TaxCalculator::tax('0.10', '5', 2)['tax'], '0.005 is half a paisa: up');
        $this->assertSame(0.00, TaxCalculator::tax('0.05', '5', 2)['tax'], '0.0025 is below half: down');
    }

    public function test_the_largest_price_a_column_holds_does_not_overflow(): void
    {
        // decimal(15,2) holds 9999999999999.99: that is 999999999999999 minor units, and at 100% the product with the rate is above PHP_INT_MAX.
        $r = TaxCalculator::tax('9999999999999.99', '100', 2);
        $this->assertSame(9999999999999.99, $r['tax']);
        $this->assertSame(19999999999999.98, $r['total']);
        $this->assertSame(999999999999999, TaxCalculator::toMinor('9999999999999.99', 2));
        $this->assertSame(999999999999999, TaxCalculator::taxMinor(999999999999999, 100000));
    }

    public function test_minor_unit_helpers_agree_with_the_array_answer(): void
    {
        $this->assertSame(99999, TaxCalculator::toMinor('999.99', 2));
        $this->assertSame(7500, TaxCalculator::percentMilli(7.5));
        $this->assertSame(5000, TaxCalculator::percentMilli('5'));
        $this->assertSame(0, TaxCalculator::percentMilli(null));
        $this->assertSame(5000, TaxCalculator::taxMinor(99999, 5000), '999.99 at 5% is 50.00 (49.9995 up)');
        $this->assertSame(1050, TaxCalculator::toMinor(1050, 0));
    }

    public function test_something_that_is_no_number_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TaxCalculator::tax('abc', 18, 2);
    }
}
