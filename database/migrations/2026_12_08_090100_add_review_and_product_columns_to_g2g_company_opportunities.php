<?php

use App\Support\ColumnExists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Slice 2 of the agentic CRM (Docs/agentic-crm/slices/S02-signals-front-door.md): two columns and four indexes on the signal itself.
 *
 *   product_master_id  the Product Master row this signal is for (g2g_product_master.id). NULL = not resolved yet; filled by
 *                      `php artisan signals:backfill-product`, never by name matching.
 *   date_status        event_date_stated | source_date_only | undated. How trustworthy the signal's age is. Filled by
 *                      `php artisan signals:backfill-date-status` from the date columns (the JSON note that also carries it exists on only some rows).
 *
 * The indexes serve the feed's own filters, none of which was indexed (business_fit, first_discovered_at and event_date are all filtered on):
 *   g2g_opp_tenant_fit_review   (sub_institute_id, business_fit, review_status)
 *   g2g_opp_tenant_discovered   (sub_institute_id, first_discovered_at)
 *   g2g_opp_tenant_event_date   (sub_institute_id, event_date)
 *   g2g_opp_tenant_pm           (sub_institute_id, product_master_id)
 *
 * ADDITIVE and idempotent: every column and index is checked first and added only if absent, and a column an index needs that is missing
 * (the live and dev tables differ) simply skips that index. Small ADD COLUMN / ADD INDEX statements on a table of about a hundred rows. The existing
 * review_status / reviewed_by / reviewed_at / review_notes columns are not touched.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_company_opportunities';

    /** @var array<string, list<string>> index name => columns */
    private const INDEXES = [
        'g2g_opp_tenant_fit_review' => ['sub_institute_id', 'business_fit', 'review_status'],
        'g2g_opp_tenant_discovered' => ['sub_institute_id', 'first_discovered_at'],
        'g2g_opp_tenant_event_date' => ['sub_institute_id', 'event_date'],
        'g2g_opp_tenant_pm' => ['sub_institute_id', 'product_master_id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! ColumnExists::has(self::TABLE, 'product_master_id')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->unsignedBigInteger('product_master_id')->nullable());
        }
        if (! ColumnExists::has(self::TABLE, 'date_status')) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->string('date_status', 20)->nullable());
        }

        foreach (self::INDEXES as $name => $columns) {
            if ($this->hasIndex($name)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! ColumnExists::has(self::TABLE, $column)) {
                    continue 2;
                }
            }
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->hasIndex($name)) {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
        foreach (['date_status', 'product_master_id'] as $column) {
            if (ColumnExists::has(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasIndex(string $name): bool
    {
        // SHOW INDEX works on every MySQL / MariaDB version the live server may run (the live one is MariaDB 10.1). The name is a quoted literal, not
        // a "?" placeholder: 10.1 rejects placeholders in SHOW statements. Other drivers use Laravel's own listing.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $quoted = DB::connection()->getPdo()->quote($name);

            return DB::select('SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = ' . $quoted) !== [];
        }

        return collect(Schema::getIndexes(self::TABLE))->contains(fn ($i) => ($i['name'] ?? null) === $name);
    }
};
