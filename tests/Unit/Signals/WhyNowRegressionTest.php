<?php

namespace Tests\Unit\Signals;

use PHPUnit\Framework\TestCase;

/**
 * Regression for a crash found in a real run (2026-11-21): the "why now" annotation reused `$eventDate`, a date OBJECT that candidate() reads again
 * a few lines later (`$eventDate?->toDateString()`), and overwrote it with a string. Any candidate with a detected school trigger then failed the whole
 * run with "Call to a member function toDateString() on string". Source-text guard, because candidate() needs a database and an AI call to execute.
 */
final class WhyNowRegressionTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
    }

    public function test_the_why_now_block_does_not_overwrite_the_event_date_object(): void
    {
        $src = $this->source();
        $this->assertDoesNotMatchRegularExpression('/\$eventDate\s*=\s*\$dated\?->toDateString\(\)/', $src, 'the string date must have its own variable');
        $this->assertStringContainsString('$whyNowDate = $dated?->toDateString();', $src);
    }

    public function test_the_event_date_object_is_only_assigned_from_a_date_parse(): void
    {
        preg_match_all('/\$eventDate\s*=\s*([^;]+);/', $this->source(), $m);
        foreach ($m[1] as $rhs) {
            $this->assertMatchesRegularExpression('/\$this->date\(|null/', $rhs, '$eventDate must stay a date object or null: ' . $rhs);
        }
    }
}
