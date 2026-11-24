<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductIntegrity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductPointerWorld;
use Tests\TestCase;

/**
 * The checker's rule `not_isolated` (S15 follow-up, 24 Nov 2026, contract section 3): a master row of organisation 0, or a Portfolio About with no organisation, is a problem
 * named `not_isolated`, with its count and its ids. After `product-master:isolate-tenants` it is 0. It is an ERROR (the command exits 1), it is looked at for every
 * organisation and for `--tenant=0`, and a run limited to one organisation does not report the rows of nobody.
 */
class ProductNotIsolatedCheckTest extends TestCase
{
    use BuildsProductPointerWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildProductPointerWorld();
    }

    /** @return array<string, mixed> */
    private function check(?int $tenant = null): array
    {
        return (new ProductIntegrity(null, $tenant))->check();
    }

    /** @return list<array<string, mixed>> */
    private function found(array $report): array
    {
        return array_values(array_filter($report['findings'], fn (array $f): bool => $f['code'] === 'not_isolated'));
    }

    public function test_the_rule_is_an_error_in_the_list_of_invariants(): void
    {
        $this->assertArrayHasKey('not_isolated', ProductIntegrity::CODES);
        $this->assertSame('error', ProductIntegrity::CODES['not_isolated'][0]);
        $this->assertArrayHasKey('not_isolated', $this->check()['counts'], 'it is counted like every other rule');
    }

    public function test_a_world_where_every_row_has_an_organisation_passes(): void
    {
        $this->product('scholar', 'Scholar K-12', 'Scholar K-12', self::T);
        $this->offer(self::T, 'P1', 'One', 'Scholar K-12');

        $report = $this->check();

        $this->assertSame(0, $report['counts']['not_isolated']);
        $this->assertSame([], $this->found($report));
    }

    public function test_a_master_row_of_organisation_0_is_not_isolated_with_its_id(): void
    {
        $shared = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', 0);
        $second = $this->product('eb', 'Enterprise Brain', 'EB', 0);
        $own = $this->product('payroll', 'Payroll', 'Payroll', self::T);

        $report = $this->check();

        $this->assertSame(2, $report['counts']['not_isolated']);
        $this->assertSame(2, $report['errors'], 'an error: the command exits 1');
        $found = $this->found($report);
        $this->assertCount(2, $found);
        $this->assertSame([$shared, $second], array_column($found, 'id'));
        $this->assertStringContainsString("master row {$shared} (product 'Scholar K-12') belongs to organisation 0, so to no organisation", $found[0]['message']);
        $this->assertNotContains($own, array_column($found, 'id'), 'a row of an organisation is never listed');
    }

    public function test_an_about_with_no_organisation_is_not_isolated_with_its_id(): void
    {
        $about = $this->offer(null, 'N1', 'Foundation One', 'Scholar K-12');
        $this->offer(self::T, 'P1', 'One', 'Scholar K-12');

        $report = $this->check();

        $this->assertSame(1, $report['counts']['not_isolated']);
        $found = $this->found($report);
        $this->assertSame($about, $found[0]['id']);
        $this->assertSame('g2g_product_offers', $found[0]['table']);
        $this->assertStringContainsString("Portfolio offer {$about} (N1, 'Foundation One') has no organisation", $found[0]['message']);
    }

    public function test_the_ids_are_sampled_but_the_count_is_exact(): void
    {
        for ($i = 0; $i < ProductIntegrity::SAMPLE + 5; $i++) {
            $this->master(['sub_institute_id' => 0, 'product_key' => 'leftover' . $i, 'kind' => 'product', 'name' => 'Leftover ' . $i]);
        }

        $report = $this->check();

        $this->assertSame(ProductIntegrity::SAMPLE + 5, $report['counts']['not_isolated']);
        $this->assertCount(ProductIntegrity::SAMPLE, $this->found($report));
    }

    public function test_a_run_limited_to_one_organisation_does_not_report_the_rows_of_nobody_but_tenant_0_does(): void
    {
        $this->product('scholar', 'Scholar K-12', 'Scholar K-12', 0);
        $this->offer(null, 'N1', 'Foundation One', 'Scholar K-12');
        $this->product('payroll', 'Payroll', 'Payroll', self::T);

        $this->assertSame(0, $this->check(self::T)['counts']['not_isolated'], 'a rule about organisation 1 does not look at the rows of nobody');
        $this->assertSame(0, $this->check(self::T2)['counts']['not_isolated']);
        $this->assertSame(2, $this->check(0)['counts']['not_isolated'], '--tenant=0 is the leftovers\' own scope');
        $this->assertSame(2, $this->check(null)['counts']['not_isolated'], 'and so is a run over every organisation');
    }

    public function test_the_command_prints_the_rule_and_exits_1_while_a_row_is_not_isolated_and_0_after_it_is_given_to_an_organisation(): void
    {
        $shared = $this->product('scholar', 'Scholar K-12', 'Scholar K-12', 0);
        $this->offer(null, 'N1', 'Foundation One', 'Scholar K-12');

        $code = Artisan::call('product-master:check');
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/not_isolated\s+error\s+2\s+master row of organisation 0/', $out);
        $this->assertStringContainsString("[not_isolated] master row {$shared}", $out);

        // what product-master:isolate-tenants does, by hand: the rows get an organisation
        DB::table('g2g_product_master')->where('id', $shared)->update(['sub_institute_id' => self::T]);
        DB::table('g2g_product_offers')->whereNull('sub_institute_id')->update(['sub_institute_id' => self::T]);

        $this->assertSame(0, $this->check()['counts']['not_isolated']);
    }
}
