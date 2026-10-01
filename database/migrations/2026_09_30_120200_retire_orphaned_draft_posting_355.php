<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire job posting 355 - the empty Draft left behind by my own mistake.
 *
 * ── WHAT THIS ROW IS ────────────────────────────────────────────────────────
 *
 * On 2026-09-19 a proof script of mine resolved a posting id through a fallback
 * (`$body['data']['id'] ?? <newest posting>`) and its cleanup then hard-deleted
 * the row that fallback landed on - a real posting on live tenant 6. I restored
 * it at its original id from the fields I could verify, and 13 content fields
 * could not be recovered. The user recreated the role properly as 357.
 *
 * So 355 is a skeleton: 12 of 28 columns populated, status Draft, nothing
 * attached to it, and 357 carries the same title with status Active, created an
 * hour later. Two postings for one role, one of them permanently incomplete.
 *
 * ── WHY A FINGERPRINT AND NOT JUST THE ID ───────────────────────────────────
 *
 * Because an id fallback is exactly what caused the original incident. This
 * matches on id AND title AND status AND tenant AND not-already-deleted, so if
 * 355 is anything other than the row described above - on another database, or
 * because somebody edited it since - it does nothing and says so.
 *
 * SOFT delete, so it is reversible: down() clears deleted_at on the same
 * fingerprint.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME (355 exists only on live):
 *   php artisan migrate --path=database/migrations/2026_09_30_120200_retire_orphaned_draft_posting_355.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_120200_retire_orphaned_draft_posting_355.php
 */
return new class extends Migration
{
    private const ID     = 355;
    private const TITLE  = 'Pre Sale Education AI Solution Consultant';
    private const STATUS = 'Draft';
    private const TENANT = 6;

    public function up(): void
    {
        if (!$this->tableExists('talent_job_postings')) {
            return;
        }

        $affected = $this->fingerprint()
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        echo $affected === 1
            ? '  posting ' . self::ID . ': retired (soft delete)' . PHP_EOL
            : '  posting ' . self::ID . ': no matching row - nothing done' . PHP_EOL;
    }

    public function down(): void
    {
        if (!$this->tableExists('talent_job_postings')) {
            return;
        }

        $this->fingerprint()
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);
    }

    /**
     * The row, identified by more than its id.
     *
     * An id alone is what went wrong the first time.
     */
    private function fingerprint()
    {
        return DB::table('talent_job_postings')
            ->where('id', self::ID)
            ->where('title', self::TITLE)
            ->where('status', self::STATUS)
            ->where('sub_institute_id', self::TENANT);
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
