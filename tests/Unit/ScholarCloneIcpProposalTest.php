<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductIcpService;
use App\Domain\Signals\Products\ScholarCloneIcpProposal as P;
use Tests\TestCase;

/** The prepared ScholarClone ICP: valid, additive, and unable to change how a lead is qualified, scored or kept fresh. No database. */
class ScholarCloneIcpProposalTest extends TestCase
{
    private function current(): array
    {
        return (array) config('signals_icp.scholar_k12');
    }

    public function test_the_proposal_passes_the_servers_own_icp_validation(): void
    {
        $validator = new IcpProfileValidator();
        // The built-in definition runs 9 catalogue searches per run, above the 0 to 6 the editor accepts. That is how it already is, and the proposal
        // keeps it (changing it would change how many searches a run makes). Whoever saves it in the editor chooses a number up to 6 there.
        $this->assertSame(['per_run.catalogue: a whole number 0 to 6'], $validator->validate($this->current()), 'the built-in definition has this one editor problem already');
        $this->assertSame($validator->validate($this->current()), $validator->validate(P::build()), 'the proposal adds no problem of its own');
    }

    public function test_only_editor_managed_settings_change_and_only_the_intended_ones(): void
    {
        $changed = P::changedSettings();
        sort($changed);
        $this->assertSame(['disqualifiers', 'guidance', 'queries', 'triggers'], $changed);
        foreach ($changed as $k) {
            $this->assertContains($k, ProductIcpService::MANAGED, "{$k} must be a setting the ICP editor can save");
        }
    }

    public function test_qualification_scoring_and_freshness_settings_are_identical_to_the_built_in_definition(): void
    {
        $p = P::build();
        foreach (['must_have', 'criteria', 'evidence_points', 'evidence_rules', 'priority', 'min_known_share', 'tiers', 'watch_rules', 'manual_flags', 'decay_by_trigger_type', 'buying_window_months', 'per_run', 'account_days', 'account_recheck_days', 'account_query', 'parts', 'product_line', 'regions', 'buyer_types', 'industry_label', 'label', 'buying_centre'] as $k) {
            $this->assertSame($this->current()[$k] ?? null, $p[$k] ?? null, "{$k} must not change");
        }
        $this->assertSame('scholar', $p['product_line']);
    }

    public function test_no_existing_trigger_query_or_exclusion_is_removed_and_freshness_of_existing_triggers_is_kept(): void
    {
        $p = P::build();
        foreach ($this->current()['triggers'] as $k => $t) {
            $this->assertArrayHasKey($k, $p['triggers']);
            $this->assertSame($t['decay'], $p['triggers'][$k]['decay'], "{$k} freshness");
            $this->assertSame($t['part'], $p['triggers'][$k]['part']);
            $this->assertSame($t['source'], $p['triggers'][$k]['source']);
            foreach ($t['phrases'] as $phrase) {
                $this->assertContains($phrase, $p['triggers'][$k]['phrases'], 'existing buying words are kept');
            }
        }
        $ids = array_column($p['queries'], 'id');
        foreach (array_column($this->current()['queries'], 'id') as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'query ids stay unique');
        foreach ($this->current()['disqualifiers'] as $d) {
            $this->assertContains($d, $p['disqualifiers']);
        }
    }

    public function test_every_trigger_maps_to_a_scholarclone_part_and_is_classed_strong_or_weak(): void
    {
        $p = P::build();
        foreach ($p['triggers'] as $key => $t) {
            $this->assertArrayHasKey($t['part'], $p['parts'], "{$key} must tie to something ScholarClone sells");
            $this->assertNotSame([], $t['phrases'], "{$key} needs buying words");
            $this->assertTrue(in_array($key, [...P::STRONG_TRIGGERS, ...P::WEAK_TRIGGERS], true), "{$key} is neither strong nor weak");
        }
        $this->assertSame([], array_intersect(P::STRONG_TRIGGERS, P::WEAK_TRIGGERS));
        $this->assertStringContainsString('WEAK', $p['guidance']);
        $this->assertStringContainsString('dated', $p['guidance']);
    }

    public function test_the_new_exclusion_matches_government_schools_and_not_private_ones(): void
    {
        $d = collect(P::build()['disqualifiers'])->firstWhere('label', 'government, aided or municipal school');
        $this->assertTrue(\App\Domain\Signals\Icp\IcpPhrases::matches($d['phrases'], 'Government School No. 4'));
        $this->assertTrue(\App\Domain\Signals\Icp\IcpPhrases::matches($d['phrases'], 'Kendriya Vidyalaya Vadodara'));
        $this->assertFalse(\App\Domain\Signals\Icp\IcpPhrases::matches($d['phrases'], 'Podar International School'));
    }

    public function test_building_the_proposal_changes_nothing_and_touches_no_other_product(): void
    {
        $before = config('signals_icp');
        P::build();
        $this->assertSame($before, config('signals_icp'));
        // Only ScholarClone has a built-in template; the other products' ICPs are not part of this proposal.
        $this->assertNull(ProductCatalog::definitions()['enterprise_brain']['icp_template']);
        $this->assertNull(ProductCatalog::definitions()['g2g']['icp_template']);
        $this->assertSame('scholar_k12', ProductCatalog::definitions()['scholarclone']['icp_template']);
    }

    public function test_nothing_in_the_application_reads_the_proposal(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && ! str_ends_with($f->getFilename(), 'ScholarCloneIcpProposal.php') && str_contains(file_get_contents($f->getPathname()), 'ScholarCloneIcpProposal')) {
                $hits[] = $f->getFilename();
            }
        }
        $this->assertSame([], $hits, 'the proposal is prepared, not applied: no runtime code may read it');
    }

    public function test_a_saved_icp_may_keep_the_built_in_nine_searches_but_not_raise_or_invent_other_values_above_six(): void
    {
        $template = $this->current();
        $validator = new IcpProfileValidator();

        $unchanged = P::build();
        $this->assertSame([], ProductIcpService::withoutBuiltInPerRunProblems($unchanged, $template, $validator->validate($unchanged)), 'the built-in 9 can be saved as it is');

        foreach ([7, 10] as $other) {
            $changed = P::build();
            $changed['per_run']['catalogue'] = $other;
            $left = ProductIcpService::withoutBuiltInPerRunProblems($changed, $template, $validator->validate($changed));
            $this->assertStringContainsString('per_run.catalogue', implode('|', $left), "{$other} is still refused");
        }

        // The total ceiling is untouched.
        config(['signals.opportunities.max_queries' => 8]);
        $left = ProductIcpService::withoutBuiltInPerRunProblems($unchanged, $template, $validator->validate($unchanged));
        $this->assertStringContainsString('must not exceed 8', implode('|', $left));
    }
}
