<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive, nullable columns for the chat lifecycle.
 *
 *  - ai_conversation_turns.trace: the 12-stage trace (and the evidence it gathered) that
 *    produced an assistant turn, so a transcript can show HOW an answer was reached long after
 *    the request. Null for every turn written before the lifecycle existed.
 *  - ai_action_requests.conversation_id: ties an approval request to the conversation that
 *    proposed it, so the audit trail reads end to end.
 *
 * SAFE TO RE-RUN: each add is guarded. Nothing existing is altered or backfilled.
 */
return new class extends Migration {
    /**
     * Schema::hasColumn() on Laravel 11+ selects `generation_expression` from information_schema,
     * which MariaDB < 10.2 does not have. Ask for the column name only so this runs on old hosts too.
     */
    private function hasColumn(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->whereRaw('table_schema = schema()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function up(): void
    {
        if (Schema::hasTable('ai_conversation_turns') && ! $this->hasColumn('ai_conversation_turns', 'trace')) {
            Schema::table('ai_conversation_turns', function (Blueprint $table) {
                $table->longText('trace')->nullable();
            });
        }

        if (Schema::hasTable('ai_action_requests') && ! $this->hasColumn('ai_action_requests', 'conversation_id')) {
            Schema::table('ai_action_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('ai_conversation_turns', 'trace')) {
            Schema::table('ai_conversation_turns', fn (Blueprint $t) => $t->dropColumn('trace'));
        }
        if ($this->hasColumn('ai_action_requests', 'conversation_id')) {
            Schema::table('ai_action_requests', fn (Blueprint $t) => $t->dropColumn('conversation_id'));
        }
    }
};
