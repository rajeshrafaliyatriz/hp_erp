<?php

namespace Tests\Feature;

use App\Domain\Portfolio\PortfolioImportService;
use Database\Seeders\G2GPortfolioSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * The two import commands and the seeder bring the Product Master in step (slice 15, track WPA1, item 2; audit gap G-D2, E2 brief step 8).
 *
 * `portfolio:import-offers`, `g2g:import-portfolio` and the G2GPortfolioSeeder write g2g_product_offers with the query builder, so the write-through of the master never ran
 * for them: an imported Sub-product had no master row until somebody ran `product-master:repair`. At the end of every REAL run they now do that themselves
 * (ProductMasterAfterImport: the repair for the organisation, then the checker). A dry run writes nothing, so it brings nothing in step. `--replace` (a hard delete of the
 * group's other offers) refuses an offer that is sold in CRM or that records still point at, with the words of the Portfolio's own delete (D-8).
 */
class PortfolioImportMirrorTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        $this->clearBackups();
    }

    protected function tearDown(): void
    {
        $this->clearBackups();
        parent::tearDown();
    }

    private function clearBackups(): void
    {
        foreach (glob(storage_path('app/offers_backup_*.json')) ?: [] as $old) {
            @unlink($old);
        }
    }

    /** @return string the path of an export file with two offers of the group "Scholar K-12" */
    private function offersFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'offers') ?: sys_get_temp_dir() . '/offers.json';
        file_put_contents($path, json_encode(['parent' => 'Scholar K-12', 'offers' => [
            ['id' => 99, 'sub_institute_id' => 77, 'offer_id' => 'P1', 'name' => 'Scholar One', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype', 'needs_solved' => '[]', 'primary_segments' => '[]', 'trigger_signals' => '[]'],
            ['id' => 98, 'sub_institute_id' => 77, 'offer_id' => 'P3', 'name' => 'Scholar Learn', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype', 'needs_solved' => '[]', 'primary_segments' => '[]', 'trigger_signals' => '[]'],
        ], 'icp_profiles' => []]));

        return $path;
    }

    /** A stand-in for the workbook importer: it writes two offers the way the real one does (no master write) and reports the counts the command prints. */
    private function standInImporter(): void
    {
        $this->app->instance(PortfolioImportService::class, new class extends PortfolioImportService {
            public function import(?string $customPath = null, ?int $tenantId = null): array
            {
                foreach (['E1', 'E2'] as $offerId) {
                    DB::table('g2g_product_offers')->insert(['sub_institute_id' => $tenantId, 'offer_id' => $offerId, 'name' => "Imported {$offerId}", 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
                }

                return ['total_in_source' => 2, 'imported' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
            }
        });
    }

    /** @return list<string> the keys of the Sub-products of one organisation in the master */
    private function subProductKeys(int $tenant): array
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('kind', 'sub_product')->orderBy('product_key')->pluck('product_key')->all();
    }

    // ---------------------------------------------------------------- portfolio:import-offers

    public function test_a_real_offers_import_brings_the_master_in_step_and_says_so(): void
    {
        $file = $this->offersFile();

        $this->artisan('portfolio:import-offers', ['file' => $file, '--tenant' => self::T, '--apply' => true])
            ->expectsOutputToContain('Product Master brought in step for organisation 1')
            ->expectsOutputToContain('php artisan product-master:repair --tenant=1 --apply')
            ->assertExitCode(0);

        $this->assertSame(['scholarclone/p1', 'scholarclone/p3'], $this->subProductKeys(self::T), 'every imported offer has its master row under the Product of its group');
        $row = DB::table('g2g_product_master')->where('product_key', 'scholarclone/p1')->first();
        $this->assertSame($this->sharedId('scholarclone'), (int) $row->parent_id);
        $this->assertSame((int) DB::table('g2g_product_offers')->where('offer_id', 'P1')->value('id'), (int) $row->offer_row_id);
        @unlink($file);
    }

    public function test_a_dry_run_writes_nothing_and_so_brings_nothing_in_step(): void
    {
        $file = $this->offersFile();

        $this->artisan('portfolio:import-offers', ['file' => $file, '--tenant' => self::T])
            ->doesntExpectOutputToContain('Product Master brought in step')->assertExitCode(0);

        $this->assertSame(0, DB::table('g2g_product_offers')->count());
        $this->assertSame([], $this->subProductKeys(self::T));
        @unlink($file);
    }

    public function test_replace_refuses_an_offer_that_is_sold_in_crm_with_the_words_of_the_portfolio_delete_and_writes_nothing(): void
    {
        $file = $this->offersFile();
        $f = $this->soldSubProduct('SCH-9', 'Sold one');   // in the group, not in the file: --replace would delete it
        $before = [DB::table('g2g_product_offers')->get()->all(), DB::table('g2g_product_master')->get()->all(), DB::table('crm_products')->get()->all()];

        foreach ([false, true] as $apply) {
            $code = Artisan::call('portfolio:import-offers', ['file' => $file, '--tenant' => self::T, '--replace' => true] + ($apply ? ['--apply' => true] : []));
            $this->assertSame(1, $code, $apply ? 'a real run is refused' : 'a dry run says it would be refused');
            $this->assertStringContainsString('SCH-9     Sold one: This sub-product is sold in CRM. Pause or archive it instead.', Artisan::output());
        }

        $this->assertEquals($before, [DB::table('g2g_product_offers')->get()->all(), DB::table('g2g_product_master')->get()->all(), DB::table('crm_products')->get()->all()], 'no store changed, not even by the offers of the file');
        $this->assertSame([], glob(storage_path('app/offers_backup_*.json')) ?: [], 'and no backup was made: nothing was going to change');
        $this->assertNotNull($this->masterOfOffer($f['offer']));
        @unlink($file);
    }

    public function test_replace_refuses_an_offer_that_records_point_at(): void
    {
        $file = $this->offersFile();
        $offer = $this->offerCreate('SCH-8', 'Has history');
        Schema::create('g2g_offer_readiness_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('offer_row_id');
            $t->string('offer_id', 50)->nullable();
        });
        DB::table('g2g_offer_readiness_log')->insert(['sub_institute_id' => self::T, 'offer_row_id' => $offer, 'offer_id' => 'SCH-8']);

        $this->artisan('portfolio:import-offers', ['file' => $file, '--tenant' => self::T, '--replace' => true, '--apply' => true])
            ->expectsOutputToContain('This sub-product has records that point at it (readiness history lines). Archive it instead.')
            ->assertExitCode(1);

        $this->assertNotNull(DB::table('g2g_product_offers')->where('id', $offer)->first(), 'the offer is still there');
        $this->assertSame(0, DB::table('g2g_product_offers')->whereIn('offer_id', ['P1', 'P3'])->count(), 'and nothing of the file was written');
        @unlink($file);
    }

    public function test_replace_still_deletes_an_offer_nothing_points_at_and_the_master_row_follows_it(): void
    {
        $file = $this->offersFile();
        $offer = $this->offerCreate('SCH-7', 'Plain one');
        $this->assertSame('active', $this->masterOfOffer($offer)->status);

        $this->artisan('portfolio:import-offers', ['file' => $file, '--tenant' => self::T, '--replace' => true, '--apply' => true])->assertExitCode(0);

        $this->assertNull(DB::table('g2g_product_offers')->where('id', $offer)->first(), 'the offer is gone');
        $row = $this->masterByKey('scholarclone/sch-7');
        $this->assertSame('archived', $row->status, 'its master row is archived by the repair, not left pointing at nothing');
        $this->assertNull($row->offer_row_id);
        $this->assertContains('scholarclone/p1', $this->subProductKeys(self::T), 'and the imported offers have theirs');
        @unlink($file);
    }

    public function test_without_a_master_table_the_import_still_works_and_says_why_nothing_followed(): void
    {
        $file = $this->offersFile();
        Schema::drop('g2g_product_master');

        $this->artisan('portfolio:import-offers', ['file' => $file, '--tenant' => self::T, '--apply' => true])
            ->expectsOutputToContain('no g2g_product_master table')->assertExitCode(0);

        $this->assertSame(2, DB::table('g2g_product_offers')->count());
        @unlink($file);
    }

    public function test_a_master_that_cannot_be_written_never_fails_the_import_and_the_exact_repair_line_is_printed(): void
    {
        $file = $this->offersFile();
        DB::unprepared("CREATE TRIGGER pm_no_insert BEFORE INSERT ON g2g_product_master BEGIN SELECT RAISE(ABORT, 'the master is broken'); END");

        $code = Artisan::call('portfolio:import-offers', ['file' => $file, '--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code, 'the import succeeded: its offers are saved');
        $output = Artisan::output();
        $this->assertStringContainsString('the Product Master could not follow', $output);
        $this->assertStringContainsString('Bring it in step with: php artisan product-master:repair --tenant=1 --apply', $output);
        $this->assertSame(2, DB::table('g2g_product_offers')->count(), 'the offers are saved all the same');
        @unlink($file);
    }

    // ---------------------------------------------------------------- g2g:import-portfolio and the seeder

    public function test_the_workbook_import_of_one_organisation_brings_its_master_in_step(): void
    {
        $this->standInImporter();

        $this->artisan('g2g:import-portfolio', ['--tenant' => self::T])
            ->expectsOutputToContain('Product Master brought in step for organisation 1')->assertExitCode(0);

        $this->assertSame(['scholarclone/e1', 'scholarclone/e2'], $this->subProductKeys(self::T));
        $this->assertSame([], $this->subProductKeys(self::T2), 'another organisation gets nothing');
    }

    public function test_the_workbook_import_with_no_organisation_makes_no_master_row_and_the_check_says_the_offers_are_not_isolated(): void
    {
        $this->standInImporter();

        $this->artisan('g2g:import-portfolio')
            ->expectsOutputToContain('Product Master brought in step for the shared rows')
            ->expectsOutputToContain('php artisan product-master:repair --tenant=0 --apply')
            ->expectsOutputToContain('Product Master check:')
            ->expectsOutputToContain('not_isolated')
            ->assertExitCode(0);

        // CHANGED ON PURPOSE (S15 follow-up, 24 Nov 2026): an offer of no organisation is nobody's: it has no master row (under organisation 0 or any other), and the checker
        // names it as `not_isolated` so that `product-master:isolate-tenants` (or a tenant on the import) puts it right.
        $this->assertSame([], $this->subProductKeys(0));
        $this->assertSame([], $this->subProductKeys(self::T));
        $this->assertSame(2, DB::table('g2g_product_offers')->whereNull('sub_institute_id')->count());
    }

    public function test_the_seeder_follows_the_master_too(): void
    {
        $this->standInImporter();

        (new G2GPortfolioSeeder())->run();   // no console attached: it must not need one

        // the seeder still writes the offers of no organisation (the foundation data); since the isolation they get no master row, and the checker lists them
        $this->assertSame([], $this->subProductKeys(0));
        $this->assertSame(2, DB::table('g2g_product_offers')->whereNull('sub_institute_id')->count());
        $this->assertSame(2, (new \App\Domain\ProductMaster\ProductIntegrity)->check()['counts']['not_isolated']);
    }
}
