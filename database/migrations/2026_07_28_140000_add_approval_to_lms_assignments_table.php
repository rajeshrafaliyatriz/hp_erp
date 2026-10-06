<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval state for learner-requested enrolments.
 *
 * The Approval Queue tab had no backing concept: lms_assignments.status is
 * free-text progress (Not Started / In Progress / Completed) with nothing about
 * permission to start, and stats() returned a hardcoded pending_approval = 0.
 *
 * An assignment an admin pushes is approved on creation. One a learner requests
 * for themselves starts as `pending` and appears in the queue until an
 * admin/HR approves or rejects it.
 *
 * Existing rows are backfilled to `approved` so nothing that is already
 * assigned suddenly disappears behind an approval gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_assignments', function (Blueprint $table) {
            $table->enum('approval_status', ['approved', 'pending', 'rejected'])
                  ->default('approved')
                  ->after('status')
                  ->index();
            // The learner who asked for it, when this was a self-request.
            $table->unsignedBigInteger('requested_by')->nullable()->after('approval_status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('requested_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');
        });

        // Anything created before approvals existed was admin-pushed.
        DB::table('lms_assignments')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('lms_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'approval_status',
                'requested_by',
                'reviewed_by',
                'reviewed_at',
                'review_note',
            ]);
        });
    }
};
