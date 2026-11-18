<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level protection against two of the same thing being saved by two requests at once: the same opportunity (one fingerprint per
 * organisation) and the same offer match for one opportunity. Until now only the application checked, so two simultaneous imports could both
 * pass the check and both insert.
 *
 * This NEVER deletes or merges anything. Before any index is added, EVERY table is checked for rows that would violate its index. If there are
 * any, the migration FAILS WITHOUT CHANGING ANYTHING (no index is added to any table, no row is touched), names the conflicting groups, and is not
 * recorded as run, so after the duplicates have been resolved by hand the same command simply runs it again. `php artisan signals:migration-check`
 * lists the conflicts without changing anything. Once the indexes exist, running it again does nothing.
 *
 * crm_leads is deliberately NOT given a unique index on source_company_opportunity_id: leads can be soft-deleted, and a unique key would then
 * stop a deleted lead's opportunity from ever being promoted again. Promotion is made safe by a transaction and a row lock instead.
 */
return new class extends Migration
{
    private const INDEXES = [
        'g2g_company_opportunities' => ['g2g_opps_tenant_fingerprint_uq', ['sub_institute_id', 'fingerprint']],
        'g2g_opportunity_matches' => ['g2g_opp_matches_signal_offer_uq', ['sub_institute_id', 'signal_type', 'signal_id', 'offer_id']],
    ];

    public function up(): void
    {
        // Pass 1: look at everything before changing anything.
        $conflicts = [];
        $pending = [];
        foreach (self::INDEXES as $table => [$name, $columns]) {
            if (! Schema::hasTable($table) || $this->hasIndex($table, $name)) {
                continue;
            }
            $groups = DB::table($table)->select($columns)->selectRaw('COUNT(*) as copies')->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(50)->get();
            if ($groups->isNotEmpty()) {
                $conflicts[$table] = $groups->map(fn ($g) => (array) $g)->all();
                Log::warning('signals.unique_index_blocked_by_duplicates', ['table' => $table, 'index' => $name, 'conflicting_groups_shown' => $groups->count(), 'groups' => $conflicts[$table]]);

                continue;
            }
            $pending[$table] = [$name, $columns];
        }
        if ($conflicts !== []) {
            throw new RuntimeException(
                'Not applied, and nothing was changed: duplicate rows would break the unique index on '
                . implode(' and ', array_map(fn ($t, $g) => $t . ' (' . count($g) . ' conflicting group(s), up to 50 shown by signals:migration-check)', array_keys($conflicts), $conflicts))
                . '. Resolve them by hand (run `php artisan signals:migration-check` to list them), then run this migration again.'
            );
        }

        // Pass 2: add the indexes.
        foreach ($pending as $table => [$name, $columns]) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            if (Schema::hasTable($table) && $this->hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($name));
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        // SHOW INDEX works on every MySQL / MariaDB version the live server may run (the live one is MariaDB 10.1); other drivers use Laravel's own listing.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::select('SHOW INDEX FROM `' . str_replace('`', '', $table) . '` WHERE Key_name = ?', [$name]) !== [];
        }

        return collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? null) === $name);
    }
};
