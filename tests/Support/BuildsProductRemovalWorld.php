<?php

namespace Tests\Support;

use App\Domain\Signals\Products\ProductCatalog;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The world the delete, move and picker tests of the S15 follow-up (24 Nov 2026) run on: the product API world of BuildsCrmProductApiWorld (two organisations, each with an
 * administrator; the Product Master, the Portfolio and the CRM catalogue on in-memory sqlite) with
 *   - NO shared row: every organisation's Products are its own, made through the real door (POST /api/signals/icp/products), and the tenant-0 built-ins the old world backfills
 *     are removed, so the tests read the same whether or not a shared row can still exist;
 *   - two more people: HR (the Portfolio's right, no row on Products or Services) and a person with no right at all;
 *   - the few tables a pointer lives in (a quote line, a price book item, a ticket, a lead, a readiness history line), cut down to the columns the checks read;
 *   - a recorder that keeps the history lines instead of writing them (`$this->events`).
 * The class that uses it defines `T` (1) and `T2` (2), as the trait it builds on needs, and calls buildProductRemovalWorld() from setUp() after parent::setUp().
 *
 * People: profile 1 is T's administrator, 2 an employee with every right on Products and Services (and none on the Portfolio), 3 is T2's administrator, 4 is HR, 5 has no right.
 */
trait BuildsProductRemovalWorld
{
    use BuildsCrmProductApiWorld;

    protected const ADMIN = 1;

    protected const SELLER = 2;

    protected const ADMIN2 = 3;

    protected const HR = 4;

    protected const NOBODY = 5;

    protected RecordingEventRecorder $events;

    protected function buildProductRemovalWorld(): void
    {
        $this->buildCrmProductApiWorld();
        // Every Product in this world is made by the test, through the real door: whatever the older world backfills (the built-in Products, shared or one copy for each organisation,
        // whichever way it is built today) is not part of it. The organisations of the fixture own the built-ins, as the isolation's setting says.
        config(['product_isolation.builtin_owner_tenants' => [self::T, self::T2]]);
        DB::table('g2g_product_master')->delete();
        DB::table('tbluserprofilemaster')->insert([
            ['id' => self::HR, 'name' => 'HR', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T],
            ['id' => self::NOBODY, 'name' => 'Nobody', 'role_key' => 'employee', 'sub_institute_id' => self::T],
        ]);

        Schema::create('crm_quote_line_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('quote_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
        });
        Schema::create('crm_price_book_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('price_book_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
        });
        Schema::create('crm_tickets', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
        });
        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('product_master_id')->nullable();
        });
        Schema::create('g2g_offer_readiness_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('offer_row_id');
        });

        $this->events = new RecordingEventRecorder;
        $this->app->instance(EventRecorder::class, $this->events);
        Cache::flush();
        ProductCatalog::forgetCustom();
    }

    // ---- who is calling ----

    /** @return array<string, string> the request headers of a new user of one organisation with one profile */
    protected function who(int $profile, ?int $tenant = null): array
    {
        return $this->headers($this->h($tenant ?? ($profile === self::ADMIN2 ? self::T2 : self::T), $profile));
    }

    // ---- rows made through the real doors ----

    /** A Product of the organisation, through the one door (POST /api/signals/icp/products); returns its master id. */
    protected function makeProduct(string $label, string $key, int $tenant = self::T): int
    {
        $this->postJson('/api/signals/icp/products', ['key' => $key, 'label' => $label], $this->who($tenant === self::T ? self::ADMIN : self::ADMIN2, $tenant))->assertCreated();

        return (int) $this->masterByKey($key, $tenant)->id;
    }

    /**
     * A Sub-product with an About only, under a Product, through the real Portfolio door.
     *
     * @return array{offer: int, master: int}
     */
    protected function makeSub(int $productId, string $offerId, string $name, int $tenant = self::T): array
    {
        $group = (string) DB::table('g2g_product_master')->where('id', $productId)->value('portfolio_name');
        $offer = $this->offerCreate($offerId, $name, $group, $tenant);

        return ['offer' => $offer, 'master' => (int) $this->masterOfOffer($offer, $tenant)->id];
    }

    /**
     * A Sub-product that is sold in CRM: the About, the catalogue row and ONE master row that holds both, all with the same name and the same Product.
     *
     * @return array{offer: int, master: int, crm: int}
     */
    protected function makeSold(int $productId, string $offerId, string $name, int $tenant = self::T): array
    {
        $sub = $this->makeSub($productId, $offerId, $name, $tenant);
        $crm = (int) DB::table('crm_products')->insertGetId([
            'sub_institute_id' => $tenant, 'product_no' => 'PRD-' . strtoupper($offerId) . 'X' . $tenant, 'item_type' => 'product', 'name' => $name, 'is_active' => 1,
            'product_master_id' => $productId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('g2g_product_master')->where('id', $sub['master'])->update(['crm_product_id' => $crm]);

        return $sub + ['crm' => $crm];
    }

    /**
     * A catalogue item (Sell only) filed under a Product, through the real CRM door.
     *
     * @return array{master: int, crm: int}
     */
    protected function makeItem(int $productId, string $name, string $type = 'product', int $tenant = self::T): array
    {
        $crm = $this->crmCreate($name, $type, $tenant, $tenant === self::T ? self::ADMIN : self::ADMIN2);
        DB::table('crm_products')->where('id', $crm)->update(['product_master_id' => $productId]);
        $master = (int) $this->masterOfCrm($crm, $tenant)->id;
        DB::table('g2g_product_master')->where('id', $master)->update(['parent_id' => $productId]);

        return ['master' => $master, 'crm' => $crm];
    }

    // ---- what points at a record ----

    protected function quoteLineHolds(int $crmId, int $lines = 1): void
    {
        for ($i = 0; $i < $lines; $i++) {
            DB::table('crm_quote_line_items')->insert(['quote_id' => 1, 'product_id' => $crmId]);
        }
    }

    protected function priceBookHolds(int $crmId): void
    {
        DB::table('crm_price_book_items')->insert(['price_book_id' => 1, 'product_id' => $crmId]);
    }

    protected function ticketAbout(int $crmId, int $tenant = self::T): void
    {
        DB::table('crm_tickets')->insert(['sub_institute_id' => $tenant, 'product_id' => $crmId]);
    }

    protected function leadAbout(int $masterId, int $tenant = self::T): int
    {
        return (int) DB::table('crm_leads')->insertGetId(['sub_institute_id' => $tenant, 'product_master_id' => $masterId]);
    }

    /** An ICP of the organisation, with the offer ids its configuration lists. @param list<string> $offerIds */
    protected function icp(string $key, array $offerIds, ?string $line = null, int $tenant = self::T): int
    {
        return (int) DB::table('g2g_icp_profiles')->insertGetId([
            'sub_institute_id' => $tenant, 'key' => $key, 'name' => "ICP {$key}", 'product_line' => $line, 'status' => 'active', 'version' => 1,
            'config' => json_encode(['offer_ids' => $offerIds]), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- reading state back ----

    /** Every row of the stores a delete or a move may touch, in id order: two snapshots that are equal mean nothing was written. @return array<string, list<object>> */
    protected function snapshot(): array
    {
        $out = [];
        foreach (['g2g_product_master', 'g2g_product_offers', 'crm_products', 'g2g_signal_products', 'g2g_icp_profiles', 'crm_quote_line_items', 'crm_leads'] as $table) {
            $out[$table] = Schema::hasTable($table) ? DB::table($table)->orderBy('id')->get()->all() : [];
        }

        return $out;
    }

    protected function masterRow(int $id): ?object
    {
        return DB::table('g2g_product_master')->where('id', $id)->first();
    }

    /** The consistency checker (`product-master:check`, invariants I1 to I5 and the rest) finds nothing to say about the organisation's records. */
    protected function assertChecker(string $why, int $tenant = self::T): void
    {
        $report = (new \App\Domain\ProductMaster\ProductIntegrity(null, $tenant))->check();
        $this->assertSame([], array_filter($report['counts']), "the checker is clean {$why}: " . json_encode($report['findings']));
    }
}
