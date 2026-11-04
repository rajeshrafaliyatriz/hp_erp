<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Defect D19 (docs/agentic-crm/_evidence/defect-register-2026-11-02.md), closed in slice 10: code filters `gtm_outreach_messages` on two things no index serves.
 *
 *   gtm_outreach_provider_message_idx   (provider_message_id(191))   a reply or bounce is matched back to its message by the Message-ID the mailer generated. The
 *                                                                    column is varchar(255), and with utf8mb4 on a Compact-row host (live) an index key may be
 *                                                                    767 bytes: 191 characters x 4 bytes = 764, so the index is a PREFIX of 191.
 *   gtm_outreach_status_scheduled_idx   (status, scheduled_for)      the scheduler's "approved, due now" query.
 *
 * ONLY WHERE ABSENT: an index is skipped when the table already has one whose leading columns are the wanted ones, under ANY name (checked on the server, not
 * assumed from the migration list), so a host that got the index another way does not get a second copy. Dev has neither (SHOW CREATE TABLE, 4 Nov 2026).
 * ADDITIVE and idempotent: indexes only, no column or row changes. down() drops only the two indexes under the names THIS file gives them, so an index that was
 * already there under another name is never touched.
 *
 * RUN ON DEV, ONE FILE AT A TIME (never an unscoped migrate):
 *   php artisan migrate --path=database/migrations/2026_12_11_150400_add_delivery_indexes_to_gtm_outreach_messages.php
 */
return new class extends Migration
{
    private const TABLE = 'gtm_outreach_messages';

    /** name => [leading columns]. The first is created with a 191-character prefix on MariaDB. */
    private const INDEXES = [
        'gtm_outreach_provider_message_idx' => ['provider_message_id'],
        'gtm_outreach_status_scheduled_idx' => ['status', 'scheduled_for'],
    ];

    private function isMariaDb(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** @return array<string, list<string>> every index of the table: name => its columns in order */
    private function existing(): array
    {
        if ($this->isMariaDb()) {
            $rows = DB::table('information_schema.STATISTICS')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', self::TABLE)
                ->orderBy('INDEX_NAME')->orderBy('SEQ_IN_INDEX')->get(['INDEX_NAME', 'COLUMN_NAME']);
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
            }

            return $out;
        }

        $out = [];
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            $out[(string) $index['name']] = array_map('strval', $index['columns']);
        }

        return $out;
    }

    /** Whether some index already starts with exactly these columns, under any name. */
    private function covered(array $columns): bool
    {
        foreach ($this->existing() as $have) {
            if (array_slice($have, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if ($this->covered($columns)) {
                continue;
            }
            if ($this->isMariaDb()) {
                $list = implode(', ', array_map(fn (string $c) => $c === 'provider_message_id' ? "`{$c}`(191)" : "`{$c}`", $columns));
                DB::statement("ALTER TABLE `gtm_outreach_messages` ADD INDEX `{$name}` ({$list})");
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $have = $this->existing();
        foreach (array_keys(self::INDEXES) as $name) {
            if (! array_key_exists($name, $have)) {
                continue;
            }
            if ($this->isMariaDb()) {
                DB::statement("ALTER TABLE `gtm_outreach_messages` DROP INDEX `{$name}`");
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
