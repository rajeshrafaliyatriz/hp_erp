<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\TargetCandidates;
use PHPUnit\Framework\TestCase;

class TargetCandidatesTest extends TestCase
{
    private function sig(string $company, array $o = []): array
    {
        return array_merge(['company' => $company, 'source_url' => 'https://news.example.com/a', 'claim_level' => 'confirmed', 'fetch_level' => 'page_text', 'created_at' => '2026-11-01 10:00:00'], $o);
    }

    public function test_merges_name_variants(): void
    {
        $r = TargetCandidates::propose([$this->sig('Cactus'), $this->sig('Cactus Communications', ['source_url' => 'https://b.example.org/x'])], []);
        $this->assertCount(1, $r);
        $this->assertSame('Cactus Communications', $r[0]['name']);
        $this->assertSame(2, $r[0]['signal_count']);
        $this->assertSame(['b.example.org', 'news.example.com'], $r[0]['source_hosts']);
    }

    public function test_excludes_tracked_names_notes_and_blank_names(): void
    {
        $r = TargetCandidates::propose([
            $this->sig('Acme Robotics'), $this->sig('Beta Works'), $this->sig('Gamma Labs', ['signal_kind' => 'public_reporting']), $this->sig('  '),
        ], ['Acme Robotics Pvt Ltd']);
        $this->assertSame(['Beta Works'], array_column($r, 'name'));
    }

    public function test_ranks_by_evidence_then_count_then_recency(): void
    {
        $r = TargetCandidates::propose([
            $this->sig('Alpha Systems', ['claim_level' => 'inference']),
            $this->sig('Bravo Systems'),
            $this->sig('Charlie Systems', ['created_at' => '2026-11-05 00:00:00']),
            $this->sig('Charlie Systems'),
            $this->sig('Delta Systems', ['created_at' => '2026-11-09 00:00:00']),
        ], []);
        // E3: Charlie (2 signals), Delta (1, newer), Bravo (1, older); then E2 Alpha.
        $this->assertSame(['Charlie Systems', 'Delta Systems', 'Bravo Systems', 'Alpha Systems'], array_column($r, 'name'));
        $this->assertSame('E3', $r[0]['best_evidence']);
        $this->assertSame('E2', $r[3]['best_evidence']);
        $this->assertSame('2026-11-05 00:00:00', $r[0]['latest_at']);
    }

    public function test_government_versus_company_kind(): void
    {
        $r = TargetCandidates::propose([
            $this->sig('Gujarat Urban Development Authority'),
            $this->sig('Pune Analytics', ['source_url' => 'https://tenders.example.gov.in/1']),   // a private company reported on a government page
            $this->sig('Odisha Skills Centre', ['website' => 'https://skills.odisha.gov.in']),    // the organisation's OWN website is a government domain
            $this->sig('Mission Office', ['domain' => 'mission.nic.in']),
            $this->sig('Plain Co'),
        ], []);
        $kinds = array_column($r, 'kind', 'name');
        $this->assertSame('government', $kinds['Gujarat Urban Development Authority']);
        $this->assertSame('company', $kinds['Pune Analytics'], 'citing a .gov.in page does not make a company a government body');
        $this->assertSame('government', $kinds['Odisha Skills Centre']);
        $this->assertSame('government', $kinds['Mission Office']);
        $this->assertSame('company', $kinds['Plain Co']);
    }

    public function test_india_first_leaves_out_organisations_that_name_another_country(): void
    {
        $r = TargetCandidates::propose([
            $this->sig('Applied Materials', ['title' => 'Applied Materials is adding 1,000 Singapore jobs', 'source_url' => 'https://www.channelnewsasia.com/a']),
            $this->sig('Nihilent', ['title' => 'Pune based Nihilent delivers business intelligence']),
            $this->sig('Mystery Co', ['title' => 'Mystery Co signs a deal']),
        ], []);
        $by = array_column($r, 'region', 'name');
        $this->assertArrayNotHasKey('Applied Materials', $by);
        $this->assertSame('inside', $by['Nihilent']);
        $this->assertSame('unknown', $by['Mystery Co']);
        $this->assertSame('Nihilent', $r[0]['name'], 'India-confirmed comes first');
    }

    public function test_all_regions_keeps_them_and_marks_them_outside(): void
    {
        $r = TargetCandidates::propose([$this->sig('Applied Materials', ['title' => 'Applied Materials is adding 1,000 Singapore jobs'])], [], 1, null);
        $this->assertSame('outside', $r[0]['region']);
    }

    public function test_a_foreign_source_host_alone_does_not_remove_an_indian_organisation(): void
    {
        $r = TargetCandidates::propose([$this->sig('Boston Consulting Group India', ['source_url' => 'https://www.consultancy.uk/news/1', 'title' => 'BCG expands in Mumbai'])], []);
        $this->assertCount(1, $r);
        $this->assertSame('inside', $r[0]['region']);
    }

    public function test_no_website_is_invented(): void
    {
        $r = TargetCandidates::propose([$this->sig('Plain Co'), $this->sig('Known Co', ['website' => 'https://known.example'])], []);
        $sites = array_column($r, 'websites', 'name');
        $this->assertSame([], $sites['Plain Co']);
        $this->assertSame(['https://known.example'], $sites['Known Co']);
    }

    public function test_empty_input_gives_empty_list(): void
    {
        $this->assertSame([], TargetCandidates::propose([], []));
        $this->assertSame([], TargetCandidates::propose([], ['Anything']));
    }

    public function test_every_row_is_proposed_for_review_and_min_filters(): void
    {
        $r = TargetCandidates::propose([$this->sig('One Hit Inc'), $this->sig('Two Hit Works'), $this->sig('Two Hit Works')], []);
        foreach ($r as $row) {
            $this->assertSame('proposed_for_review', $row['status']);
        }
        $this->assertSame(['Two Hit Works'], array_column(TargetCandidates::propose([$this->sig('One Hit Inc'), $this->sig('Two Hit Works'), $this->sig('Two Hit Works')], [], 2), 'name'));
    }
}
