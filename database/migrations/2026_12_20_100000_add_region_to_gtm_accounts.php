<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Region-wise prospecting: an account belongs to ONE product, ONE customer type and ONE place (a country, or a state, or a city).
 *
 *  - region_country / region_state / region_city: the place, in the names the location data (and Market Signals) uses. An account added under
 *    "India" has only the country; under Gujarat, the country and state; and so on. Empty = no place chosen (every older account).
 *  - region_key: a short fingerprint of those three names, '' when there is no place. It exists only so the one-account-per-website rule can include
 *    the place: the same firm may be a Gujarat partner and a Maharashtra partner as two separate accounts, never mixed.
 *
 * Older accounts keep an empty place: nothing is guessed. They stay visible under "All regions" and are not shown under any one region.
 * ADDITIVE except for the website index, which is replaced by a wider one (it can only allow more rows than before, never fewer).
 * SHOW ... is used on MySQL/MariaDB because the live MariaDB 10.1 cannot answer information_schema reliably (as in the other GTM migrations).
 */
return new class extends Migration
{
    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(string $column): bool
    {
        if ($this->isMysql()) {
            return ! empty(DB::select("SHOW COLUMNS FROM gtm_accounts LIKE '{$column}'"));
        }

        return Schema::hasColumn('gtm_accounts', $column);
    }

    private function hasIndex(string $index): bool
    {
        if ($this->isMysql()) {
            return ! empty(DB::select("SHOW INDEX FROM gtm_accounts WHERE Key_name = '{$index}'"));
        }

        return collect(Schema::getIndexes('gtm_accounts'))->contains(fn ($i) => ($i['name'] ?? null) === $index);
    }

    public function up(): void
    {
        if (! Schema::hasTable('gtm_accounts')) {
            return;
        }

        foreach (['region_country' => 80, 'region_state' => 80, 'region_city' => 80, 'region_key' => 16] as $column => $length) {
            if (! $this->hasColumn($column)) {
                Schema::table('gtm_accounts', function (Blueprint $t) use ($column, $length) {
                    $column === 'region_key' ? $t->string($column, $length)->default('') : $t->string($column, $length)->nullable();
                });
            }
        }

        if (! $this->hasIndex('gtm_accounts_tenant_domain_scope_region_unique')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->unique(['sub_institute_id', 'domain', 'product_key', 'customer_model', 'region_key'], 'gtm_accounts_tenant_domain_scope_region_unique');
            });
        }
        // The narrower rule is now implied by the wider one, and would wrongly refuse the same website in another place.
        if ($this->hasIndex('gtm_accounts_tenant_domain_scope_unique')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->dropUnique('gtm_accounts_tenant_domain_scope_unique');
            });
        }
        if (! $this->hasIndex('gtm_accounts_tenant_region_idx')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->index(['sub_institute_id', 'product_key', 'customer_model', 'region_country'], 'gtm_accounts_tenant_region_idx');
            });
        }
    }

    /** Dropping the place would merge separate accounts back together; leave it in place. */
    public function down(): void
    {
    }
};
