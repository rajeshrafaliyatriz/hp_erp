<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * gtm_workflows.activated_at: a workflow reacts only to events recorded AFTER it was (re)activated.
 *
 *   php artisan migrate --path=database/migrations/2026_10_11_160000_gtm_workflow_activation.php
 *
 * Why: the reactor delivers every event it has not seen yet. On first use that is the whole history, and a
 * workflow switched on today must not fire on last month's events. Activating (or re-activating after a pause)
 * stamps this column; the engine ignores older events. Existing workflows (none were active) get no stamp, so
 * they react to nothing until someone activates them.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if ($this->has('gtm_workflows') && ! $this->hasColumn('gtm_workflows', 'activated_at')) {
            Schema::table('gtm_workflows', fn (Blueprint $t) => $t->timestamp('activated_at')->nullable());
        }
    }

    public function down(): void
    {
        if ($this->has('gtm_workflows') && $this->hasColumn('gtm_workflows', 'activated_at')) {
            Schema::table('gtm_workflows', fn (Blueprint $t) => $t->dropColumn('activated_at'));
        }
    }
};
