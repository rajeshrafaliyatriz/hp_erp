<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the Main Dashboard and Agentic AI the same capability flags every other module's `ai_modules` row has.
 *
 * Both rows were created from the menu with an EMPTY `capabilities` value, so the AI Stack's Models tab saw a
 * module that uses no capability at all and had nothing to show or to bind a model to. The six other modules
 * carry `{"conversational":true,...}`; the assistant is asked from the Main Dashboard and Agentic AI exactly as
 * it is from them, and agents run in Agentic AI, so these two get the flags that are true of them.
 *
 * ADDITIVE AND GUARDED. Only the two platform rows (no tenant), only while `capabilities` is still empty, so
 * a value an administrator or another migration already set is never overwritten. Nothing else is touched.
 * SAFE TO RE-RUN.
 */
return new class extends Migration {
    /** @var array<string, array<string, bool>> */
    private const FLAGS = [
        'main_dashboard' => ['conversational' => true, 'generative' => false, 'agent' => true, 'workflow' => false, 'ontology' => false],
        'agentic_ai' => ['conversational' => true, 'generative' => false, 'agent' => true, 'workflow' => false, 'ontology' => false],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_modules')) {
            return;
        }

        foreach (self::FLAGS as $key => $flags) {
            DB::table('ai_modules')
                ->where('module_key', $key)
                ->whereNull('sub_institute_id')
                ->where(fn ($q) => $q->whereNull('capabilities')->orWhere('capabilities', ''))
                ->update(['capabilities' => json_encode($flags), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_modules')) {
            return;
        }

        foreach (self::FLAGS as $key => $flags) {
            DB::table('ai_modules')
                ->where('module_key', $key)
                ->whereNull('sub_institute_id')
                ->where('capabilities', json_encode($flags))
                ->update(['capabilities' => null, 'updated_at' => now()]);
        }
    }
};
