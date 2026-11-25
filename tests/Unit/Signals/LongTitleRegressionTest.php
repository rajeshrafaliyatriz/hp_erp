<?php

namespace Tests\Unit\Signals;

use PHPUnit\Framework\TestCase;

/**
 * Regression from a real run (2026-11-22, run 120): a source title longer than the 500-character source_title column failed the INSERT with
 * "Data too long for column 'source_title'" and took the whole run down with it. Source-text guard, because candidate() needs a database and an AI call.
 */
final class LongTitleRegressionTest extends TestCase
{
    public function test_the_stored_source_title_is_cut_to_fit_its_column(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("'source_title' => \$support['title'] === null ? null : mb_substr((string) \$support['title'], 0, 480)", $src);
        $this->assertStringNotContainsString("'source_url' => \$support['url'], 'source_title' => \$support['title'],", $src, 'the uncut title must not come back');
    }
}
