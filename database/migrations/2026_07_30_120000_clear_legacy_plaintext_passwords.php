<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbluser') && Schema::hasColumn('tbluser', 'plain_password')) {
            DB::table('tbluser')->whereNotNull('plain_password')->update(['plain_password' => null]);
        }
    }

    public function down(): void
    {
        // Cleared passwords cannot and must not be restored.
    }
};
