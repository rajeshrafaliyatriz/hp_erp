<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-07b — the *_id columns the backfill writes into.
 *
 * STRICTLY ADDITIVE. No text column is touched, renamed or dropped: per
 * requirement 4, the text stays until the new columns are proven, and R8 governs
 * every drop when we get to one. We are not there.
 *
 * NULLABLE ON PURPOSE. 13,777 resolutions in these three mappings have no
 * canonical counterpart, and they are HELD as NULL - not guessed, not
 * auto-created. A NULL here means "this text names something that does not exist
 * in the canonical table", which is a fact worth storing.
 *
 * s_user_jobrole_task.jobrole_id already exists (0 of 85,663 populated) and is
 * reused rather than duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('s_user_skill_jobrole', function (Blueprint $table) {
            if (!Schema::hasColumn('s_user_skill_jobrole', 'jobrole_id')) {
                $table->unsignedBigInteger('jobrole_id')->nullable()->after('jobrole');
                $table->index(['jobrole_id'], 'idx_susj_jobrole_id');
            }
            if (!Schema::hasColumn('s_user_skill_jobrole', 'skill_id')) {
                $table->unsignedBigInteger('skill_id')->nullable()->after('skill');
                $table->index(['skill_id'], 'idx_susj_skill_id');
            }
        });

        if (!Schema::hasColumn('s_user_jobrole_task', 'jobrole_id')) {
            Schema::table('s_user_jobrole_task', function (Blueprint $table) {
                $table->unsignedBigInteger('jobrole_id')->nullable()->after('jobrole');
            });
        }
    }

    public function down(): void
    {
        Schema::table('s_user_skill_jobrole', function (Blueprint $table) {
            $table->dropIndex('idx_susj_jobrole_id');
            $table->dropIndex('idx_susj_skill_id');
            $table->dropColumn(['jobrole_id', 'skill_id']);
        });
    }
};
