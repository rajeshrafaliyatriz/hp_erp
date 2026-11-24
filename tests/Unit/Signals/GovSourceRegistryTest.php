<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Sources\GovSourceRegistry as G;
use Tests\TestCase;

/** The government source register: who may read what, and that the shipped list matches the readability check. */
final class GovSourceRegistryTest extends TestCase
{
    public function test_only_government_runs_of_a_non_school_product_get_sources(): void
    {
        $g = new G($this->shipped());
        $this->assertNotEmpty($g->readableFor('g2g', 'b2g'));
        $this->assertNotEmpty($g->readableFor('enterprise_brain', 'b2g'));
        $this->assertSame([], $g->readableFor('g2g', 'b2b'), 'a business run gets no government list');
        $this->assertSame([], $g->readableFor('g2g', 'b2c'));
        $this->assertSame([], $g->readableFor('scholarclone', 'b2g'), 'the school product keeps its own education inventory');
    }

    public function test_only_usable_sources_are_offered_for_reading(): void
    {
        foreach ((new G($this->shipped()))->readableFor('g2g', 'b2g') as $r) {
            $this->assertSame(G::USABLE, $r['status']);
        }
    }

    public function test_sources_that_need_a_feed_or_a_terms_review_are_never_offered(): void
    {
        $g = new G($this->shipped());
        $offered = array_column($g->readableFor('g2g', 'b2g'), 'id');
        foreach ($g->all() as $r) {
            if (in_array($r['status'], [G::FEED, G::REVIEW], true)) {
                $this->assertNotContains($r['id'], $offered, $r['id'] . ' must not be read until it is arranged');
            }
        }
        $this->assertNotEmpty($g->needingFeed());
    }

    public function test_the_shipped_list_matches_the_readability_check(): void
    {
        $g = new G($this->shipped());
        $this->assertSame(['needs_feed' => 16, 'needs_review' => 1, 'usable' => 9], $this->sorted($g->counts()), 'update when scripts/gov-source-check.php is re-run');
        $this->assertCount(26, $g->all());
    }

    public function test_every_row_is_https_with_a_unique_id(): void
    {
        $ids = [];
        foreach ((new G($this->shipped()))->all() as $r) {
            $this->assertStringStartsWith('https://', $r['url']);
            $this->assertNotContains($r['id'], $ids);
            $ids[] = $r['id'];
            $this->assertContains($r['status'], [G::USABLE, G::REVIEW, G::FEED]);
        }
    }

    public function test_a_malformed_row_is_ignored_rather_than_offered(): void
    {
        $g = new G(['sources' => [['', 'No id', 'https://a.example', 'x', 'usable', ''], ['x1', 'Plain http', 'http://a.example', 'x', 'usable', ''], ['ok', 'Fine', 'https://a.example', 'x', 'usable', '']]]);
        $this->assertSame(['ok'], array_column($g->all(), 'id'));
    }

    public function test_the_register_contains_no_school_inventory(): void
    {
        foreach ((new G($this->shipped()))->all() as $r) {
            $this->assertDoesNotMatchRegularExpression('/school|cbse|k-?12|scholar/i', $r['name'] . ' ' . $r['url']);
        }
    }

    /** The register file shipped in this tree (read directly, so the test does not depend on which config directory the test app loads). */
    private function shipped(): array
    {
        return require dirname(__DIR__, 3) . '/config/signals_gov_sources.php';
    }

    /** @param array<string,int> $a */
    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }
}
