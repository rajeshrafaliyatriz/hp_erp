<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE TWO-STEP VERIFICATION LIVES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ITS OWN TABLE, NOT THREE MORE COLUMNS ON tbluser
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `tbluser` is already 99 columns wide and is SELECTed by dozens of controllers,
 * several with `select('*')`. A TOTP secret on it would travel into responses
 * nobody audited for it - and a shared secret in a JSON payload is the whole
 * factor, gone.
 *
 * A separate table means the secret is only ever read by code that asks for it by
 * name, and `user_two_factor` appears in exactly one service.
 *
 * ── MariaDB 10.1 ON LIVE ────────────────────────────────────────────────────
 *
 * Live is 10.1.48 with ROW_FORMAT=Compact, so an index prefix is limited to 767
 * bytes. The only index here is the unique `user_id` - 8 bytes - so there is
 * nothing to compute, but `Schema::hasTable()` still cannot be used to guard it:
 * on 10.1 that query asks for `generation_expression`, a column that version does
 * not have, and throws. `information_schema` directly, as everywhere else here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tablePresent()) {
            return;
        }

        Schema::create('user_two_factor', function (Blueprint $table) {
            $table->bigIncrements('id');

            /*
             * UNIQUE. One enrolment per person: a second row would mean two secrets
             * could both open the account, and nothing in the flow would say which.
             * No foreign key, matching the rest of this schema - `tbluser` rows are
             * soft-deleted and a cascade would take the enrolment with a
             * deactivation that is meant to be reversible.
             */
            $table->unsignedBigInteger('user_id')->unique();

            /*
             * Nullable, because the tenant is denormalised here purely so an
             * administrator report can be tenant-scoped without a join. Identity
             * still comes from `user_id`; this column is never trusted for
             * authorisation.
             */
            $table->unsignedBigInteger('sub_institute_id')->nullable();

            /*
             * The base32 shared secret. 160 bits encodes to 32 characters; 64 gives
             * room for a longer secret later without another migration.
             *
             * Stored in plain text, and that is not an oversight: TOTP verification
             * needs the secret itself to recompute the code, so there is no hashed
             * form that could work. What protects it is that only `TwoFactor` reads
             * this column. A password can be hashed because verification only ever
             * compares; a shared secret cannot.
             */
            $table->string('secret', 64);

            /*
             * Null until a code has proved the person's app holds the same secret.
             *
             * This column is the difference between "enrolling" and "enrolled".
             * Treating a stored secret as protection would lock somebody out of
             * their own account by closing the tab mid-enrolment - the secret would
             * be live and their app would never have received it.
             */
            $table->timestamp('confirmed_at')->nullable();

            /*
             * A JSON array of HASHED single-use codes.
             *
             * Hashed because these are the fallback that bypasses the second
             * factor: readable in the database, they would be ten permanent
             * skeleton keys per account. Removed from the array when spent, so
             * there is nothing left to replay.
             */
            $table->text('recovery_codes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Dropping this disables two-step verification for everybody who had it.
     *
     * Stated plainly because it is not a neutral rollback: it is a reduction in
     * security for every enrolled account, and their authenticator apps will keep
     * showing codes for a secret the server has forgotten. Re-enrolment is the only
     * way back - there is no secret to restore.
     */
    public function down(): void
    {
        if (!$this->tablePresent()) {
            return;
        }

        Schema::drop('user_two_factor');
    }

    private function tablePresent(): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['user_two_factor']
        );

        return (int) ($found->n ?? 0) > 0;
    }
};
