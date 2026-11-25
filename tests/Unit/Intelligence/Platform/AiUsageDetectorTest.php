<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Detectors\Platform\AiUsageDetector;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AiUsageDetectorTest extends PlatformTestCase
{
    private const LINKS = ['/ai/usage-cost'];

    private function detector(): AiUsageDetector
    {
        return new AiUsageDetector($this->db(), $this->thresholds());
    }

    private function admin(int $tenant = 1, string $role = 'administrator')
    {
        return $this->platformCaller($role, self::LINKS, $tenant);
    }

    private function calls(int $tenant, int $n, string $outcome = 'success', string $module = 'lms.course_builder', array $o = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->addAiCall($tenant, $module, $outcome, '2026-11-15 08:00:00', $o);
        }
    }

    /** @return array<string, \App\Domain\Intelligence\Insight> keyed by the insight subject */
    private function bySubject(\App\Domain\Intelligence\DetectorResult $r): array
    {
        $out = [];
        foreach ($r->insights as $i) {
            $out[explode(':', $i->key)[1]] = $i;
        }

        return $out;
    }

    public function test_failure_share_and_refused_calls_are_reported_and_refused_calls_stay_out_of_the_denominator(): void
    {
        $this->calls(1, 20);
        $this->calls(1, 3, 'failed', 'agents.chat');
        $this->calls(1, 2, 'refused');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $by = $this->bySubject($result);
        $this->assertSame(['failures', 'refused'], array_keys($by));
        $this->assertSame('attention', $by['failures']->severity, '3 of 23 reached calls = 13.0%');
        $ev = $this->evidenceByLabel($by['failures']);
        $this->assertSame(23, $ev['AI calls that reached a provider']['value']);
        $this->assertSame(13.0, (float) $ev['Share of calls that failed']['value']);
        $this->assertSame(3, $ev['Failed in module agents.chat']['value']);
        $this->assertSame('high', $by['refused']->severity);
        $this->assertSame(2, $this->evidenceByLabel($by['refused'])['Calls refused by a quota']['value']);
        $this->assertSame('/ai/usage-cost', $by['failures']->deepLink);
    }

    public function test_a_low_failure_share_raises_nothing(): void
    {
        $this->calls(1, 40);
        $this->calls(1, 1, 'failed');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_quota_proximity_uses_the_products_own_consumption_rule(): void
    {
        $this->calls(1, 20, 'success', 'lms.course_builder', ['input_tokens' => 400, 'output_tokens' => 100]);    // 10,000 this month
        $this->calls(1, 3, 'refused', 'lms.course_builder', ['input_tokens' => 99999, 'output_tokens' => 99999]);  // refused: excluded from consumption
        $this->calls(92, 50, 'success', 'lms.course_builder', ['input_tokens' => 9999, 'output_tokens' => 9999]);   // another tenant
        $this->addQuota(1, null, 'month', 11000);                       // 90.9% >= 80: near
        $this->addQuota(1, 'lms.course_builder', 'month', 9000);        // 111%: over
        $this->addQuota(1, 'agents.chat', 'month', 100);                // no usage: fine
        $this->addQuota(1, null, 'day', 1000000);                       // far below
        $this->addQuota(1, null, 'month', 1, 80, 0);                    // inactive: ignored
        $this->addQuota(92, null, 'month', 1);                          // another tenant: ignored

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $by = $this->bySubject($result);
        $this->assertArrayHasKey('quota', $by);
        $this->assertSame('high', $by['quota']->severity);
        $ev = $this->evidenceByLabel($by['quota']);
        $this->assertSame(4, $ev['Active quotas']['value']);
        $this->assertSame(2, $ev['At or past their warning level']['value']);
        $this->assertSame(1, $ev['At or past 100%']['value']);
        $this->assertSame(91, $ev['Quota whole organisation per month']['value']);
        $this->assertSame(111, $ev['Quota lms.course_builder per month']['value']);
    }

    public function test_quota_below_its_warning_level_is_quiet(): void
    {
        $this->calls(1, 20, 'success', 'lms.course_builder', ['input_tokens' => 400, 'output_tokens' => 100]);
        $this->addQuota(1, null, 'month', 100000);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_cost_concentration_is_per_currency_as_stored_and_never_converted(): void
    {
        $this->calls(1, 24, 'success', 'lms.course_builder', ['estimated_cost' => 1.0, 'cost_currency' => 'USD']);
        $this->calls(1, 6, 'success', 'agents.chat', ['estimated_cost' => 1.0, 'cost_currency' => 'USD']);
        $this->calls(1, 15, 'success', 'lms.course_builder', ['estimated_cost' => 10.0, 'cost_currency' => 'INR']);
        $this->calls(1, 15, 'success', 'agents.chat', ['estimated_cost' => 10.0, 'cost_currency' => 'INR']);
        $this->calls(1, 10, 'success', 'agents.chat');                                                                  // no price: never zero cost, never counted
        $this->calls(92, 40, 'success', 'other.module', ['estimated_cost' => 99999.0, 'cost_currency' => 'ZZZ']);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $by = $this->bySubject($result);
        $this->assertSame(['cost_concentration'], array_keys($by));
        $ev = $this->evidenceByLabel($by['cost_concentration']);
        $this->assertSame(30.0, (float) $ev['Cost in USD (as stored, last 30 days)']['value']);
        $this->assertSame('USD', $ev['Cost in USD (as stored, last 30 days)']['unit']);
        $this->assertSame(80.0, (float) $ev['Largest module in USD: lms.course_builder']['value']);
        $this->assertSame(30, $ev['Priced calls in USD']['value']);
        $this->assertArrayNotHasKey('Cost in INR (as stored, last 30 days)', $ev, 'a 50/50 currency is not concentrated');
        $this->assertStringNotContainsString('ZZZ', $this->jsonOf($result));
        $this->assertStringNotContainsString('99999', $this->jsonOf($result));
        foreach ($ev as $label => $line) {
            $this->assertStringNotContainsStringIgnoringCase('total cost', $label, 'there is no cross-currency total');
        }
    }

    public function test_without_the_per_currency_columns_the_usd_column_is_read_as_usd_only(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $t) {
            $t->dropColumn(['estimated_cost', 'cost_currency']);
        });
        $this->calls(1, 24, 'success', 'lms.course_builder', ['estimated_cost_usd' => 1.0]);
        $this->calls(1, 6, 'success', 'agents.chat', ['estimated_cost_usd' => 1.0]);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertArrayHasKey('Cost in USD (as stored, last 30 days)', $this->evidenceByLabel($result->insights[0]));
    }

    public function test_without_any_cost_column_the_cost_metric_is_skipped_not_failed(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $t) {
            $t->dropColumn(['estimated_cost', 'cost_currency', 'estimated_cost_usd']);
        });
        $this->calls(1, 30);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
    }

    public function test_too_few_calls_and_no_quota_is_insufficient(): void
    {
        $this->calls(1, 5);
        $this->calls(1, 4, 'failed');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(9, $result->coverage->observed);
    }

    public function test_other_tenants_and_old_calls_are_never_counted(): void
    {
        $this->calls(1, 25);
        $this->calls(92, 60, 'failed');
        $this->addAiCall(1, 'lms.course_builder', 'failed', '2026-01-01 00:00:00');

        $result = $this->detector()->detect($this->admin(1), $this->clock());

        $this->assertSame([], $result->insights);
        $this->assertSame(25, $result->coverage->observed);
    }

    public function test_the_error_text_model_user_and_related_record_are_never_selected(): void
    {
        $this->calls(1, 20);
        $this->calls(1, 5, 'failed');
        $this->addQuota(1, null, 'month', 1000);

        $result = null;
        $sql = strtolower(implode("\n", $this->sqlOf(function () use (&$result) {
            $result = $this->detector()->detect($this->admin(), $this->clock());
        })));

        foreach (['error', 'model', 'user_id', 'related_'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql, $forbidden);
        }
        $this->assertStringNotContainsString(self::SECRET_SENTINEL, $this->jsonOf($result));
    }

    public function test_a_missing_usage_table_is_data_unavailable(): void
    {
        Schema::drop('ai_usage_events');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $result->state);
        $this->assertNull($result->summary()['insight_count']);
    }

    public function test_authorization_needs_the_usage_and_cost_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator', ['/ai/policies'])));
        $this->assertTrue($this->detector()->authorizes($this->platformCaller('employee', self::LINKS)));
    }
}
