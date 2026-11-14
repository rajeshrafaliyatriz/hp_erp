<?php

namespace Tests\Support;

use App\Domain\ProductMaster\ProductPointers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The world the unified READ API tests run on (slice 15, P2-E1), on in-memory sqlite: the pair review world (the real offer, master and catalogue tables, the 22 pointer tables
 * of the registry, two organisations) with
 *
 *   - documents that have the columns the Usage tab reads (number, stage, date) and a Recycle Bin mark on every table that holds a pointer's owner,
 *   - the real audit projection table (g2g_audit_log) and people with a name,
 *   - the columns Signals counts read, and
 *   - the people the brief names, by profile id:
 *       1  administrator of organisation 1 (full rights on Products and Services)
 *       2  sales-like: an employee with every right on Products and Services and none on the Portfolio (role)  = "Sell only"
 *       3  administrator of organisation 2
 *       5  HR-like: hr_manager, writes the Portfolio by role and holds NO row on Products or Services      = "Portfolio only"
 *       6  may VIEW Products (a product's Sell side) and nothing else
 *       7  may VIEW Services (a service's Sell side) and nothing else
 *       8  nobody: no rights at all (not even the CRM root)
 *
 * The class that uses it defines nothing: T (1) and T2 (2) come from BuildsProductPointerWorld.
 */
trait BuildsProductReadWorld
{
    use BuildsProductUnifyWorld;

    protected const ADMIN = 1;

    protected const SALES = 2;

    protected const ADMIN2 = 3;

    protected const HR = 5;

    protected const PRODUCTS_VIEWER = 6;

    protected const SERVICES_VIEWER = 7;

    protected const NOBODY = 8;

    protected function buildReadWorld(): void
    {
        // The documents first: the pointer tables the unify world builds from the registry only add what is missing.
        Schema::create('crm_quotes', function ($t) {
            $t->id();
            $t->string('quote_no', 100)->nullable();
            $t->string('quote_stage', 100)->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_sales_orders', function ($t) {
            $t->id();
            $t->string('so_no', 100)->nullable();
            $t->string('so_stage', 100)->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_invoices', function ($t) {
            $t->id();
            $t->string('invoice_no', 100)->nullable();
            $t->string('invoice_stage', 100)->nullable();
            $t->date('invoice_date')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('crm_price_books', function ($t) {
            $t->id();
            $t->string('price_book_no', 191)->nullable();
            $t->string('name', 191)->nullable();
            $t->string('currency', 191)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        $this->buildUnifyWorld();

        // A Recycle Bin mark on every table that owns a pointer's row (documents, tickets ...), as the real tables have.
        foreach (ProductPointers::toCrmProduct() as $e) {
            $owner = is_string($e['scope']) ? $e['table'] : $e['scope']['parent'];
            if (! Schema::hasColumn($owner, 'deleted_at')) {
                Schema::table($owner, fn ($t) => $t->timestamp('deleted_at')->nullable());
            }
        }
        // The columns Signals counts read.
        Schema::table('g2g_company_opportunities', fn ($t) => $t->string('business_fit', 50)->nullable());
        Schema::table('g2g_company_opportunities', fn ($t) => $t->string('relevant_offer_id', 50)->nullable());
        Schema::table('g2g_company_opportunities', fn ($t) => $t->boolean('is_sample')->default(false));
        // The projection the History tab reads, and the people it names.
        (require base_path('database/migrations/2026_08_10_150000_create_g2g_audit_log.php'))->up();
        Schema::table('tbluser', function ($t) {
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });

        DB::table('tbluserprofilemaster')->insert([
            ['id' => self::HR, 'name' => 'HR', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T],
            ['id' => self::PRODUCTS_VIEWER, 'name' => 'Products viewer', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => self::SERVICES_VIEWER, 'name' => 'Services viewer', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => self::NOBODY, 'name' => 'Nobody', 'role_key' => 'employee', 'sub_institute_id' => self::T],
        ]);
        $grant = fn (int $profile, int $menu) => DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => 1]);
        foreach ([self::HR, self::PRODUCTS_VIEWER, self::SERVICES_VIEWER] as $profile) {
            $grant($profile, 199);
        }
        $grant(self::PRODUCTS_VIEWER, 468);
        $grant(self::SERVICES_VIEWER, 469);
    }

    /** @return array<string, string> the headers of a new user of a profile (profile 3 belongs to organisation 2) */
    protected function as(int $profile): array
    {
        return $this->headers($this->h($profile === self::ADMIN2 ? self::T2 : self::T, $profile));
    }
}
