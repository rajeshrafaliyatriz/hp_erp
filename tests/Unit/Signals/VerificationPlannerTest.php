<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\VerificationPlanner;
use Tests\TestCase;

/** Corroboration query planning. No database, no network. */
class VerificationPlannerTest extends TestCase
{
    public function test_quotes_org_and_caps_at_three(): void
    {
        $p = VerificationPlanner::plan(['organisation' => 'Acme Corp', 'trigger' => 'smart classroom rollout', 'kind' => 'enterprise', 'event' => 'new campus', 'region' => 'Gujarat']);
        $this->assertGreaterThanOrEqual(1, count($p));
        $this->assertLessThanOrEqual(3, count($p));
        foreach ($p as $row) {
            $this->assertStringContainsString('"Acme Corp"', $row['query']);
            $this->assertContains($row['aims_for'], ['official_site', 'government_portal', 'reputable_news', 'tender_document']);
            $this->assertNotSame('', $row['reason']);
        }
    }

    public function test_government_gets_tender_and_portal_queries(): void
    {
        $p = VerificationPlanner::plan(['organisation' => 'Gujarat Education Department', 'trigger' => 'digital classrooms', 'kind' => 'government_body', 'region' => 'Gujarat']);
        $aims = array_column($p, 'aims_for');
        $this->assertContains('tender_document', $aims);
        $this->assertContains('government_portal', $aims);
        $this->assertMatchesRegularExpression('/tender|RFP/i', implode(' ', array_column($p, 'query')));
    }

    public function test_no_duplicates_and_strips_tracking_junk(): void
    {
        $p = VerificationPlanner::plan(['organisation' => 'Acme', 'trigger' => 'expansion https://x.example/a?utm_source=tw #hashtag @handle fbclid=abc', 'kind' => 'partner']);
        $queries = array_map('mb_strtolower', array_column($p, 'query'));
        $this->assertSame($queries, array_values(array_unique($queries)));
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('http', $q);
            $this->assertStringNotContainsString('utm_', $q);
            $this->assertStringNotContainsString('fbclid', $q);
            $this->assertStringNotContainsString('#', $q);
            $this->assertStringNotContainsString('@', $q);
        }
    }

    public function test_empty_organisation_gives_no_queries_and_output_is_deterministic(): void
    {
        $this->assertSame([], VerificationPlanner::plan(['organisation' => '  ']));
        $in = ['organisation' => 'Acme', 'trigger' => 't', 'kind' => 'enterprise'];
        $this->assertSame(VerificationPlanner::plan($in), VerificationPlanner::plan($in));
    }

    public function test_inner_quotes_in_name_cannot_break_the_phrase(): void
    {
        $p = VerificationPlanner::plan(['organisation' => 'Acme "Best" Ltd']);
        $this->assertStringStartsWith('"Acme Best Ltd"', $p[0]['query']);
    }
}
