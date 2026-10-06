<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the CANDIDATE is looking for, beside what the posting offers.
 *
 * `talent_job_applications` already had `employment_type` and the public apply
 * form never sent it - the column has been there, accepted by the controller,
 * and left empty on every public application. Adding `work_mode` alongside it
 * makes the pair symmetric with the posting, which now states both.
 *
 * Nullable: a candidate who does not say is not assumed to want On-site.
 *
 * Live is MariaDB 10.1 - VARCHAR + PHP const, never ENUM.
 */
return new class extends Migration
{
    private const TABLE = 'talent_job_applications';
    private const COLUMN = 'work_mode';

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c > 0;
    }

    public function up(): void
    {
        if ($this->columnExists(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string(self::COLUMN, 20)->nullable()->after('employment_type');
        });
    }

    public function down(): void
    {
        if (!$this->columnExists(self::TABLE, self::COLUMN)) {
            return;
        }

        $inUse = DB::table(self::TABLE)->whereNotNull(self::COLUMN)->count();

        if ($inUse > 0) {
            throw new RuntimeException(
                $inUse . ' application(s) state a preferred work mode. Dropping '
                . self::COLUMN . ' would discard what those candidates asked for. '
                . 'Clear the column deliberately first if this is really intended.'
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
