<?php

use Illuminate\Database\Migrations\Migration;

/**
 * RETIRED - intentionally does nothing.
 *
 * This migration used to insert developer-written example rows (invented policy rules, prompt text, report layouts
 * and chat suggestions, labelled "(example)") that every organisation then saw as if they were its own settings.
 * An AI Stack tab shows what an administrator saved or what the system measured, never a worked example, so nothing is
 * seeded any more. The file is kept, empty, only so environments that already ran it keep a consistent migration
 * history. Rows it created earlier are removed by `php artisan ai:remove-seeded-examples`.
 */
return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
