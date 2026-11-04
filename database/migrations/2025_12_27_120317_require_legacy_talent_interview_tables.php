<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REQUIRED_TABLES = [
        'talent_interview_panel',
        'talent_interview_schedules',
    ];

    public function up(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException(
                    "The legacy table `{$table}` has no recoverable creation migration in this repository. "
                    . 'Provide its authoritative schema before running fresh-install migrations.'
                );
            }
        }
    }

    public function down(): void
    {
    }

    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
