<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductFacet;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * D-E4 of the products parity audit (slice 15, track WPB; decision of 14 Nov 2026, option (a)): the Product facet of the lead list and of the lead export also finds the
 * leads filed under one of the Product's Sub-products. The forms point at level 1 but the data does not insist (LeadLinks::productVisible accepts any visible master row),
 * so an exact match hid every lead filed one level down.
 *
 * What is proven: a Product facet finds the leads of the Product and of its ACTIVE Sub-products; a Sub-product facet finds its own rows only (also an archived one's); a
 * lead of another Product, or of another Product's Sub-product, is not matched; an archived child is not matched by its Product; the children are the organisation's own and
 * the shared ones, never another organisation's; another organisation's leads are never listed; the same on the CSV export. The signal feed is in SignalProductFacetChildrenTest.
 */
class LeadProductFacetChildrenTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
        $this->user = $this->leadsUser();
    }

    private function master(int $tenant, string $kind, string $name, ?int $parent = null, string $status = 'active'): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => $tenant, 'kind' => $kind, 'product_key' => strtolower(str_replace(' ', '_', $name)) . '_' . uniqid(), 'name' => $name, 'status' => $status, 'parent_id' => $parent,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lead(string $name, ?int $product, int $tenant = self::T): int
    {
        return $this->makeLead($tenant, ['last_name' => $name, 'product_master_id' => $product, 'assigned_to' => $this->user['id'], 'email' => 'p' . uniqid() . '@school.example']);
    }

    /** @return list<string> the last names a list call returned, sorted */
    private function names(string $query): array
    {
        $names = array_column($this->getJson('/api/crm/leads' . $query, $this->user['h'])->assertOk()->json('data.items'), 'lastName');
        sort($names);

        return $names;
    }

    /** @return list<string> the last names of the leads in the CSV export of a request, sorted */
    private function exported(string $query, array $candidates): array
    {
        $csv = $this->get('/api/crm/leads/export' . $query, $this->user['h'])->assertOk()->streamedContent();
        $found = array_values(array_filter($candidates, fn (string $name) => str_contains($csv, $name)));
        sort($found);

        return $found;
    }

    /** One Product with an active and an archived Sub-product, a second Product with a Sub-product, and a lead on each, plus one with no Product. */
    private function world(): array
    {
        $p = $this->master(self::T, 'product', 'Alpha Product');
        $active = $this->master(self::T, 'sub_product', 'Alpha Active Sub', $p);
        $archived = $this->master(self::T, 'sub_product', 'Alpha Archived Sub', $p, 'archived');
        $q = $this->master(self::T, 'product', 'Beta Product');
        $qSub = $this->master(self::T, 'sub_product', 'Beta Sub', $q);
        $this->lead('AlphaDirect', $p);
        $this->lead('AlphaUnderActive', $active);
        $this->lead('AlphaUnderArchived', $archived);
        $this->lead('BetaDirect', $q);
        $this->lead('BetaUnderSub', $qSub);
        $this->lead('NothingAtAll', null);

        return compact('p', 'active', 'archived', 'q', 'qSub');
    }

    private const ALL = ['AlphaDirect', 'AlphaUnderActive', 'AlphaUnderArchived', 'BetaDirect', 'BetaUnderSub', 'NothingAtAll'];

    public function test_a_product_facet_finds_the_leads_of_the_product_and_of_its_active_sub_products(): void
    {
        $w = $this->world();

        $this->assertSame(['AlphaDirect', 'AlphaUnderActive'], $this->names("?product_id={$w['p']}"));
        $this->assertSame(['BetaDirect', 'BetaUnderSub'], $this->names("?product_id={$w['q']}"));
        $this->assertSame(2,$this->getJson("/api/crm/leads?product_id={$w['p']}", $this->user['h'])->json('data.pagination.total'));
    }

    public function test_a_sub_product_facet_finds_its_own_rows_only_also_an_archived_ones(): void
    {
        $w = $this->world();

        $this->assertSame(['AlphaUnderActive'], $this->names("?product_id={$w['active']}"));
        $this->assertSame(['AlphaUnderArchived'], $this->names("?product_id={$w['archived']}"), 'an archived Sub-product keeps its own facet');
        $this->assertSame(['BetaUnderSub'], $this->names("?product_id={$w['qSub']}"));
    }

    public function test_an_archived_sub_product_is_not_matched_by_its_product_and_nothing_else_changes(): void
    {
        $w = $this->world();

        $this->assertNotContains('AlphaUnderArchived', $this->names("?product_id={$w['p']}"));
        // Restore it: the Product now finds it too.
        DB::table('g2g_product_master')->where('id', $w['archived'])->update(['status' => 'active']);
        $this->assertSame(['AlphaDirect', 'AlphaUnderActive', 'AlphaUnderArchived'], $this->names("?product_id={$w['p']}"));
        // No facet, zero, empty and a facet nobody holds behave as before.
        $this->assertSame(self::ALL, $this->names(''));
        $this->assertSame(self::ALL, $this->names('?product_id=0'));
        $this->assertSame([], $this->names('?product_id=999999'));
    }

    public function test_the_children_are_the_organisations_own_and_the_shared_ones_never_another_organisations(): void
    {
        $shared = $this->master(0, 'product', 'Shared Product');
        $sharedSub = $this->master(0, 'sub_product', 'Shared Sub', $shared);
        $theirs = $this->master(self::T2, 'sub_product', 'Their Sub', $shared);   // another organisation's Sub-product under the shared Product
        $this->lead('OnShared', $shared);
        $this->lead('OnSharedSub', $sharedSub);
        $this->lead('PointsAtTheirSub', $theirs);                                  // a forged pointer: this organisation may not see that row
        $this->lead('TheirLead', $theirs, self::T2);

        $this->assertSame(['OnShared', 'OnSharedSub'], $this->names("?product_id={$shared}"), 'the shared child counts, another organisation\'s child does not');
        $this->assertSame(['PointsAtTheirSub'], $this->names("?product_id={$theirs}"), 'asking for the row itself is the exact match it always was, only this organisation\'s own leads');
        $this->assertSame([$shared, $sharedSub], ProductFacet::ids(self::T, $shared));
        $this->assertSame([$shared, $sharedSub, $theirs], ProductFacet::ids(self::T2, $shared), 'the other organisation sees its own child and the shared one');
    }

    public function test_the_product_facet_is_one_query_for_the_children_not_one_per_row(): void
    {
        $w = $this->world();
        for ($i = 0; $i < 20; $i++) {
            $this->lead('Extra' . $i, $w['active']);
        }
        $count = function () use ($w): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/crm/leads?product_id={$w['p']}&per_page=100", $this->user['h'])->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'g2g_product_master') && str_contains($q['query'], 'parent_id'))->count();
            DB::disableQueryLog();

            return $n;
        };
        $count();   // warm
        $this->assertSame(1, $count(), 'the children are read once for the whole request');
    }

    public function test_the_export_follows_the_same_facet(): void
    {
        $w = $this->world();

        $this->assertSame(['AlphaDirect', 'AlphaUnderActive'], $this->exported("?product_id={$w['p']}", self::ALL));
        $this->assertSame(['AlphaUnderActive'], $this->exported("?product_id={$w['active']}", self::ALL));
        $this->assertSame(['AlphaUnderArchived'], $this->exported("?product_id={$w['archived']}", self::ALL));
        $this->assertSame(['BetaDirect', 'BetaUnderSub'], $this->exported("?product_id={$w['q']}", self::ALL));
        $this->assertSame(self::ALL, $this->exported('', self::ALL));
    }

    public function test_another_organisations_leads_are_never_listed_under_a_facet(): void
    {
        $w = $this->world();
        $this->lead('TheirOnAlphaSub', $w['active'], self::T2);
        $this->lead('TheirOnAlpha', $w['p'], self::T2);

        $this->assertSame(['AlphaDirect', 'AlphaUnderActive'], $this->names("?product_id={$w['p']}"));
    }
}
