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
    public function up(): void
    {
        if (Schema::hasTable('ai_conversation_turns') && ! Schema::hasColumn('ai_conversation_turns', 'trace')) {
            Schema::table('ai_conversation_turns', function (Blueprint $table) {
                $table->json('trace')->nullable();
            });
        }

        if (Schema::hasTable('ai_action_requests') && ! Schema::hasColumn('ai_action_requests', 'conversation_id')) {
            Schema::table('ai_action_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_conversation_turns', 'trace')) {
            Schema::table('ai_conversation_turns', fn (Blueprint $t) => $t->dropColumn('trace'));
        }
        if (Schema::hasColumn('ai_action_requests', 'conversation_id')) {
            Schema::table('ai_action_requests', fn (Blueprint $t) => $t->dropColumn('conversation_id'));
        }
    }
};
