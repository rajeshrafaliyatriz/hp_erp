<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE REFERENT SWEEP'S OUTPUT, WRITTEN INTO THE SCHEMA.
 *
 * Run while it was cheap rather than after the next G-DATA-11. Method: a candidate
 * table survives only if it has FULL COVERAGE of the referenced values AND 100%
 * tenant agreement - because G-DATA-11 established that A JOIN THAT SUCCEEDS IS
 * NOT A REFERENT.
 *
 * ⚠ THE FINDING: `competency_kasba_item.item_id` IS THE NEXT `competency_id`.
 *   200 distinct values, and BOTH `s_users_skills` and `master_skills` survive
 *   both tests. It is the TARGET half of the TARGET/HOLDING pair - so the KASBA
 *   target model itself had no declared referent. Declared here as
 *   `s_users_skills`, which is what X-20 and the tenant-3 seed both wrote.
 *
 * TWO MORE ARE UNAMBIGUOUS IN THE DATA AND STILL DECLARED, because the DATA is not
 * what the next person reads - the NAME is, and both names are actively
 * misleading:
 *
 *   course_id -> `sub_std_map`. THERE IS NO `courses` OR `lms_courses` TABLE.
 *       I guessed both while building X-11 and got "table missing" twice before
 *       finding it by reading a join.
 *   user_id  -> `tbluser`. NOT `users`, which exists and holds 14 rows. Earlier
 *       in this phase a token probe used `users` and measured 14 tokens instead
 *       of 4,511.
 *
 * THE EMPTY COLUMNS ARE INCLUDED DELIBERATELY. An empty column has no wrong
 * answer yet, which is precisely the state `competency_id` was in before anyone
 * noticed - the cheapest possible moment to declare it.
 */
return new class extends Migration
{
    /** column => [referent, note] */
    private const DECLARATIONS = [
        'item_id' => ['s_users_skills',
            'FK -> s_users_skills.id (the tenant-owned skill library). The TARGET half of '
            . 'TARGET/HOLDING: NULL here means the item is held as item_label instead. '
            . 'NOT master_skills, which is the GLOBAL library and shares the id range.'],

        'kasba_item_id' => ['competency_kasba_item',
            'FK -> competency_kasba_item.id (one KASBA item inside a bundle).'],

        'jobrole_id' => ['s_user_jobrole',
            'FK -> s_user_jobrole.id (the TENANT-OWNED job role). NOT s_jobrole, which is '
            . 'the global seed library (Q-C1).'],

        'course_id' => ['sub_std_map',
            'FK -> sub_std_map.id. THE COURSE TABLE IS CALLED sub_std_map for historical '
            . 'reasons. There is no `courses` or `lms_courses` table.'],

        'user_id' => ['tbluser',
            'FK -> tbluser.id. NOT `users`, which exists, holds ~14 rows and is not the '
            . 'employee table.'],

        'assessor_id' => ['tbluser', 'FK -> tbluser.id (the person who rated). NOT `users`.'],
        'actor_id'    => ['tbluser', 'FK -> tbluser.id (the person who acted). NOT `users`.'],
        'head_user_id' => ['tbluser', 'FK -> tbluser.id (the department head). NOT `users`.'],
        'event_id'    => ['g2g_event', 'FK -> g2g_event.id (append-only event store).'],
        'certification_type_id' => ['certification_type', 'FK -> certification_type.id.'],
    ];

    private const TABLES = [
        'competency', 'competency_kasba_item', 'competency_kasba_rating',
        'jobrole_competency_map', 'course_competency_map', 'course_jobrole_map',
        'certification_competency_map', 'g2g_notification', 'g2g_terminology',
        'g2g_event', 'g2g_event_delivery', 'g2g_audit_log', 'lms_assignments',
        'hrms_departments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) continue;

            foreach (self::DECLARATIONS as $column => [$referent, $note]) {
                if (!Schema::hasColumn($table, $column)) continue;

                // Read the real type first (R15) - rewriting a column with a
                // guessed type is how a bigint quietly becomes an int.
                $col = collect(DB::select("SHOW COLUMNS FROM `$table` LIKE '$column'"))->first();
                if (!$col) continue;

                $null = $col->Null === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $col->Default !== null ? " DEFAULT '" . addslashes($col->Default) . "'" : '';

                DB::statement(
                    "ALTER TABLE `$table` MODIFY `$column` {$col->Type} $null$default COMMENT '"
                    . addslashes($note) . "'"
                );
            }
        }
    }

    /** DELIBERATELY EMPTY - removing a declaration restores the ambiguity. */
    public function down(): void
    {
    }
};
