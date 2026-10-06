<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The link a candidate uses to follow their own application.
 *
 * ── WHY A TABLE AND NOT COLUMNS ON talent_job_applications ──────────────────
 *
 * The applications table holds live recruiting data on both hosts. A token is a
 * different lifetime from the application it points at - it expires, it can be
 * re-issued, and it is read by an anonymous request - so it is kept beside the
 * application rather than inside it, exactly as offer links are.
 *
 * ── WHY IT IS NOT SINGLE-USE ────────────────────────────────────────────────
 *
 * The offer and assessment links are single-use because each opens a decision
 * taken once. This one opens a STATUS, which the candidate has every reason to
 * check again next week. It therefore expires on a date and is not consumed.
 *
 * ── LIVE IS MariaDB 10.1, ROW_FORMAT=Compact ────────────────────────────────
 *
 * 767-byte index prefix cap, so the unique key is on the 64-char hash (CHAR(64)
 * utf8mb4 = 256 bytes) and nothing else. No json column, no ENUM.
 */
return new class extends Migration
{
    private const TABLE = 'talent_application_tracking';

    /** Schema::hasTable() throws on the 10.1 host; information_schema does not. */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }

    public function up(): void
    {
        if ($this->tableExists(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('candidate_id')->nullable();

            // Only the hash is stored. The raw token exists in the candidate's
            // inbox and nowhere else, so a database read cannot impersonate them.
            $table->char('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // 256 bytes, well inside the 767-byte prefix cap on Compact rows.
            $table->unique('token_hash', 'app_track_token_hash_unique');
            // One live link per application; re-issuing overwrites it.
            $table->unique(['application_id'], 'app_track_application_unique');
            $table->index(['sub_institute_id'], 'app_track_tenant_idx');
        });
    }

    /**
     * Refuses to drop a table that holds rows.
     *
     * A candidate's only route back to their own application is in here. Losing
     * it silently on a rollback would break links already sitting in inboxes.
     */
    public function down(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        if (DB::table(self::TABLE)->count() > 0) {
            throw new RuntimeException(
                self::TABLE . ' holds tracking links that candidates are using. '
                . 'Refusing to drop it. Empty it deliberately first if this is really intended.'
            );
        }

        Schema::drop(self::TABLE);
    }
};
