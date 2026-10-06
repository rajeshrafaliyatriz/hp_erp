<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLICE 1, ITEM 5 — the measurement. Per KASBA item, per employee.
 *
 * Nothing stored this before. `s_skill_matrix` holds knowledge/ability/behaviour/
 * attitude as FREE TEXT across 169 rows - prose, not a rating, and nothing can be
 * rolled up from it.
 *
 * TENANT COLUMN FROM THE START. This is measurement data: it decides promotion
 * readiness and gap reporting, and G-DATA-08 is why it is here on day one rather
 * than added after someone notices.
 *
 * WHAT IS DELIBERATELY ABSENT: a row meaning "not measured". Unmeasured is the
 * ABSENCE of a row, never a row with rating 0 - because 0 is a score and absence
 * is not. Item 7 reports it as UNMEASURED, neither zero nor pass.
 *
 * Rated against the KASBA ITEM, not the competency: an employee is rated per
 * item and proficiency is DERIVED (Q-E2). Rating a competency directly would
 * make the roll-up a second opinion rather than the only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('competency_kasba_rating')) {
            return;
        }

        Schema::create('competency_kasba_rating', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('user_id');            // the employee rated
            $table->unsignedBigInteger('kasba_item_id');      // competency_kasba_item.id

            // 1-5, the same scale as jobrole_competency_map.required_proficiency.
            // NOT NULL: a row exists because a rating was given.
            $table->tinyInteger('rating');

            $table->unsignedBigInteger('assessor_id')->nullable();   // NULL = SYSTEM
            $table->string('source', 32)->default('manual');         // manual | assessment | import
            $table->text('note')->nullable();
            $table->dateTime('rated_at', 3);

            $table->timestamps();

            // One current rating per employee per item. History belongs in the
            // event store, not in a second row here.
            $table->unique(['sub_institute_id', 'user_id', 'kasba_item_id'], 'uq_ckr');
            $table->index(['sub_institute_id', 'user_id'], 'idx_ckr_tenant_user');
            $table->index(['kasba_item_id'], 'idx_ckr_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_kasba_rating');
    }
};
