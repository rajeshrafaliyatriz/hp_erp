<?php

namespace Tests\Unit\Intelligence\Service;

use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\Thresholds;
use Tests\Intelligence\IntelligenceTestCase;

/** config/intelligence.php must pass RAW env values to Thresholds (which ignores non-numeric ones) and must fail CLOSED on an unparseable switch. */
class IntelligenceConfigTest extends IntelligenceTestCase
{
    private array $touched = [];

    private function setEnv(string $key, ?string $value): void
    {
        $this->touched[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $key => [$getenv, $env, $server]) {
            $getenv === false ? putenv($key) : putenv("{$key}={$getenv}");
            $env === null ? $_ENV[$key] = null : $_ENV[$key] = $env;
            $server === null ? $_SERVER[$key] = null : $_SERVER[$key] = $server;
            if ($env === null) {
                unset($_ENV[$key]);
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            }
        }
        parent::tearDown();
    }

    private function config(): array
    {
        return require config_path('intelligence.php');
    }

    public function test_an_empty_or_misspelt_numeric_env_value_never_becomes_zero(): void
    {
        foreach (['', 'abc', 'five', '  '] as $bad) {
            $this->setEnv('INTELLIGENCE_MIN_GROUP_SIZE', $bad);
            $this->setEnv('INTELLIGENCE_DEALS_MIN_OPEN', $bad);

            $thresholds = Thresholds::with($this->config()['thresholds']);

            $this->assertSame(Thresholds::DEFAULTS['min_group_size'], $thresholds->int('min_group_size'), "min_group_size for env value '{$bad}'");
            $this->assertSame(Thresholds::DEFAULTS['deals_min_open'], $thresholds->int('deals_min_open'), "deals_min_open for env value '{$bad}'");
        }
    }

    public function test_a_valid_numeric_env_value_is_honoured(): void
    {
        $this->setEnv('INTELLIGENCE_MIN_GROUP_SIZE', '8');

        $this->assertSame(8, Thresholds::with($this->config()['thresholds'])->int('min_group_size'));
    }

    public function test_every_threshold_default_has_a_config_entry_and_nothing_is_cast(): void
    {
        $this->assertSame([], array_diff(array_keys(Thresholds::DEFAULTS), array_keys($this->config()['thresholds'])));
        $this->assertStringNotContainsString('(int) env', file_get_contents(config_path('intelligence.php')), 'a cast turns an empty value into 0 and weakens a gate');
    }

    public function test_the_enabled_switch_is_robust_and_fails_closed(): void
    {
        $this->assertTrue(InsightService::parseEnabled(null), 'unset means enabled');
        $this->assertTrue(InsightService::parseEnabled(true));
        $this->assertFalse(InsightService::parseEnabled(false));
        foreach (['true', '1', 'yes', 'on', 'TRUE', ' On '] as $on) {
            $this->assertTrue(InsightService::parseEnabled($on), "'{$on}'");
        }
        foreach (['false', '0', 'no', 'off', 'FALSE'] as $off) {
            $this->assertFalse(InsightService::parseEnabled($off), "'{$off}'");
        }
        foreach (['', 'maybe', 'tru', 'enabled', '2', 'yess', [], 3.5] as $garbage) {
            $this->assertFalse(InsightService::parseEnabled($garbage), 'unparseable must fail closed: ' . json_encode($garbage));
        }
    }

    public function test_the_config_file_uses_the_robust_switch(): void
    {
        $this->setEnv('INTELLIGENCE_ENABLED', 'definitely');
        $this->assertFalse($this->config()['enabled']);

        $this->setEnv('INTELLIGENCE_ENABLED', 'false');
        $this->assertFalse($this->config()['enabled']);

        $this->setEnv('INTELLIGENCE_ENABLED', null);
        $this->assertTrue($this->config()['enabled']);

        $this->setEnv('INTELLIGENCE_ENABLED', 'true');
        $this->assertTrue($this->config()['enabled']);
    }
}
