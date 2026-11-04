<?php

namespace Tests\Unit;

use App\Http\Controllers\AI\AiModuleController;
use PHPUnit\Framework\TestCase;

/**
 * The module usage screen shows one cost figure. Once rates carry a currency (rupees by default), that figure must say which currency it is in: a
 * recorded cost comes from estimated_cost_usd (dollars); a computed one is the rate's own currency. Printing a rupee amount as dollars is wrong data.
 */
class AiModuleCostCurrencyTest extends TestCase
{
    /** @param array<string, mixed>|null $rate @return array{0: float|null, 1: string, 2: string|null, 3: string|null} */
    private function resolve(?int $prompt, ?int $completion, ?float $recorded, ?array $rate): array
    {
        $controller = (new \ReflectionClass(AiModuleController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'resolveCost');

        return $method->invoke($controller, $prompt, $completion, $recorded, $rate);
    }

    public function test_a_recorded_cost_is_in_dollars(): void
    {
        [$cost, $source, $reason, $currency] = $this->resolve(1000, 500, 0.0125, ['input_per_1k' => 0.0288, 'output_per_1k' => 0.1152, 'currency' => 'INR']);

        $this->assertSame([0.0125, 'recorded', null, 'USD'], [$cost, $source, $reason, $currency], 'estimated_cost_usd is dollars whatever the current rate card says');
    }

    public function test_a_computed_cost_is_in_the_rates_own_currency(): void
    {
        // 47,350 input and 6,150 output tokens at 0.0288 and 0.1152 rupees per 1,000: 1.36368 + 0.70848 = 2.07216 rupees.
        [$cost, $source, , $currency] = $this->resolve(47350, 6150, null, ['input_per_1k' => 0.0288, 'output_per_1k' => 0.1152, 'currency' => 'INR']);

        $this->assertSame('computed', $source);
        $this->assertSame('INR', $currency);
        $this->assertEqualsWithDelta(2.07216, $cost, 0.000001);
    }

    public function test_a_rate_from_a_host_without_the_currency_column_is_read_as_dollars_as_before(): void
    {
        [, , , $currency] = $this->resolve(1000, 1000, null, ['input_per_1k' => 0.001, 'output_per_1k' => 0.002]);

        $this->assertSame('USD', $currency);
    }

    public function test_no_cost_has_no_currency(): void
    {
        $this->assertSame([null, 'unavailable'], array_slice($this->resolve(null, null, null, null), 0, 2));
        $this->assertNull($this->resolve(null, null, null, null)[3], 'no token counts: no cost, no currency');
        $noPrice = $this->resolve(1000, 1000, null, ['input_per_1k' => null, 'output_per_1k' => null, 'currency' => 'INR']);
        $this->assertSame([null, 'unavailable', null], [$noPrice[0], $noPrice[1], $noPrice[3]], 'a model with no price is unknown, never zero, and has no currency');
    }
}
