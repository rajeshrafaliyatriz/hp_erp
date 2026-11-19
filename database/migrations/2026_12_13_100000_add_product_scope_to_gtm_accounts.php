<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Product-wise prospecting: every account belongs to ONE product and ONE customer type (b2b customers, b2c partners, b2g government).
 *
 *  - product_key, customer_model: the scope an account was added for (nullable, so older code that creates accounts keeps working).
 *  - Accounts that existed before this are placed under scholarclone / b2b, the scope every one of them was working for. Rows are only
 *    updated where the scope is still empty, so running this twice changes nothing.
 *  - The old rule "one account per website in an organisation" becomes "one account per website, product and customer type": the same
 *    firm can be a ScholarClone customer and a G2G partner as two separate accounts, each with its own stage, contacts and notes.
 *
 * ADDITIVE except for that one index, which is replaced by a wider one (it can only allow more rows than before, never fewer).
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

        if (! $this->hasColumn('product_key') || ! $this->hasColumn('customer_model')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                if (! $this->hasColumn('product_key')) {
                    $t->string('product_key', 40)->nullable();
                }
                if (! $this->hasColumn('customer_model')) {
                    $t->string('customer_model', 3)->nullable();
                }
            });
        }

        DB::table('gtm_accounts')->whereNull('product_key')->update(['product_key' => 'scholarclone']);
        DB::table('gtm_accounts')->whereNull('customer_model')->update(['customer_model' => 'b2b']);

        if ($this->hasIndex('gtm_accounts_tenant_domain_unique')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->dropUnique('gtm_accounts_tenant_domain_unique');
            });
        }
        if (! $this->hasIndex('gtm_accounts_tenant_domain_scope_unique')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->unique(['sub_institute_id', 'domain', 'product_key', 'customer_model'], 'gtm_accounts_tenant_domain_scope_unique');
            });
        }
        if (! $this->hasIndex('gtm_accounts_tenant_scope_idx')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->index(['sub_institute_id', 'product_key', 'customer_model'], 'gtm_accounts_tenant_scope_idx');
            });
        }
    }

    /** Dropping the scope would merge separate accounts back together; leave it in place. */
    public function down(): void
    {
    }
};
