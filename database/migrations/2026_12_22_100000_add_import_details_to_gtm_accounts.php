<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Everything a company file says about a company, kept with the company. An import file can carry columns that are not one of the standard account fields
 * (where the contact was verified, a research status, a confidence rating, an address ...). They are saved on the account as one small JSON document,
 * heading => text, and shown on the company's detail view, so nothing in a file is dropped.
 *
 * ADDITIVE: one nullable TEXT column (not JSON: the live MariaDB 10.1 has no JSON type). Safe to run twice.
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
        if (Schema::hasTable('gtm_accounts') && ! $this->hasColumn('import_details')) {
            Schema::table('gtm_accounts', function (Blueprint $t) {
                $t->text('import_details')->nullable();
            });
        }
    }

    /** Dropping it would delete what was imported; leave it in place. */
    public function down(): void
    {
    }
};
