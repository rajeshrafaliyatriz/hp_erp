<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Support\DefaultRegion;
use App\Domain\Signals\Support\ForeignCountryHint as F;
use App\Domain\Signals\Support\RegionScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** India first: the default region, and the conservative "names another country and never India" test. Pure; no network, no database. */
final class IndiaRegionTest extends TestCase
{
    #[DataProvider('outsideCases')]
    public function test_outside_india_only_when_another_country_is_named_and_india_never_is(string $text, bool $expected): void
    {
        $this->assertSame($expected, F::outsideIndia($text), $text);
    }

    public static function outsideCases(): array
    {
        return [
            'Singapore jobs' => ['Applied Materials is adding 1,000 Singapore jobs and needs one structured view', true],
            'US federal agency' => ['OPM headcount fell 35% and the Office of Personnel Management lost institutional knowledge, says the United States GAO', true],
            'Bhutan agency' => ['The GovTech Agency of Bhutan issued an RFP', true],
            'Indian state body' => ['Government of Maharashtra issued a notice for a Master System Integrator', false],
            'India named with Singapore' => ['An Indian firm expands into Singapore with a Mumbai head office', false],
            'Indian city only' => ['Pune based Nihilent delivers business intelligence', false],
            'Odisha' => ['The Odisha government operationalised 24 new schools', false],
            'no country at all' => ['The department issued a request for proposal for a system integrator', false],
            'empty' => ['', false],
            'Gov in domain text' => ['tenders gov in notice for UAE visitors', false],
        ];
    }

    public function test_india_is_the_default_when_nothing_is_requested(): void
    {
        $r = DefaultRegion::resolve(null, 'India');
        $this->assertSame('default', $r['source']);
        $this->assertNotNull($r['scope']);
        $this->assertSame('india', $r['scope']->singleCountry());
    }

    public function test_a_requested_region_always_wins(): void
    {
        $requested = RegionScope::fromInput([['country' => 'India', 'state' => 'Gujarat']]);
        $r = DefaultRegion::resolve($requested, 'India');
        $this->assertSame('request', $r['source']);
        $this->assertSame($requested, $r['scope']);
    }

    #[DataProvider('offValues')]
    public function test_a_blank_or_none_setting_switches_the_default_off(?string $configured): void
    {
        $r = DefaultRegion::resolve(null, $configured);
        $this->assertSame('none', $r['source']);
        $this->assertNull($r['scope']);
    }

    public static function offValues(): array
    {
        return [[null], [''], ['none'], ['OFF'], ['all']];
    }

    public function test_an_unusable_setting_never_stops_a_run(): void
    {
        $r = DefaultRegion::resolve(null, str_repeat('x', 500));
        $this->assertContains($r['source'], ['none', 'default']);
    }

    public function test_the_shipped_default_is_india(): void
    {
        $cfg = require __DIR__ . '/../../../config/signals.php';
        $this->assertSame('India', $cfg['region']['default']);
        $this->assertTrue($cfg['region']['reject_named_foreign']);
    }
}
