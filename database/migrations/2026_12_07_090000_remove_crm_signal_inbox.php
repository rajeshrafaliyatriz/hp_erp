<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_automation_rules')) {
            DB::table('crm_automation_rules')->where('trigger_module', 'signal')->delete();
        }

        Schema::dropIfExists('crm_signal_topics');
        Schema::dropIfExists('crm_signal_inbox');
    }

    public function down(): void
    {
        // Removed inbox data and its automation rules cannot be restored.
    }
};
