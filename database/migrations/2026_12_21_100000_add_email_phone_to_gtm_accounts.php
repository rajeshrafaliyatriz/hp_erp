<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A company's own contact details. An account had a name, website, industry, location, size and notes, but no place for the company's general
 * email and phone, so an import that carried them had nowhere to put them except on a person.
 *
 * ADDITIVE: two nullable columns, nothing is changed or removed. Safe to run twice.
 * SHOW COLUMNS is used on MySQL/MariaDB because the live MariaDB 10.1 cannot answer information_schema reliably (as in the other GTM migrations).
 */
return new class extends Migration
{
    private function hasColumn(string $column): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select("SHOW COLUMNS FROM gtm_accounts LIKE '{$column}'"));
        }

        return Schema::hasColumn('gtm_accounts', $column);
    }

    public function up(): void
    {
        if (! Schema::hasTable('gtm_accounts')) {
            return;
        }
        foreach (['email' => 191, 'phone' => 50] as $column => $length) {
            if (! $this->hasColumn($column)) {
                Schema::table('gtm_accounts', function (Blueprint $t) use ($column, $length) {
                    $t->string($column, $length)->nullable();
                });
            }
        }
    }

    /** Dropping them would delete what was imported; leave them in place. */
    public function down(): void
    {
    }
};
