<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITEM 5 — THE REPORTING LINE AND THE ROLE MODEL. Q-B1, A5, A6, Q-D1.
 *
 * Additive. Every column is nullable and unread until the resolver uses it.
 *
 * ─── WHY THIS COMES BEFORE POPULATING THE RIGHTS MATRIX ──────────────────────
 * 03-rbac-matrix.md §3.1–3.7 is written against NINE roles. Three exist. Applying
 * that matrix today would mean collapsing nine columns into three, which is
 * re-deriving decided permissions. This migration creates the model the matrix is
 * written against, so 4b can apply it faithfully instead of guessing.
 *
 * ─── WHAT IT ADDS ────────────────────────────────────────────────────────────
 *   tbluser.reporting_manager_id      Q-B1. The reporting line every approval flow
 *                                     and team scope depends on.
 *   hrms_departments.head_user_id     Q-B1. The department head.
 *   tbluserprofilemaster.role_key     A STABLE MACHINE NAME. Renaming a role in a
 *                                     customer's UI must never break access — the
 *                                     resolver keys on role_key, never on `name`.
 *   tbluserprofilemaster.data_scope   self | team | department | organization (A6).
 *                                     SCOPE IS NEVER INDIVIDUALLY OVERRIDABLE, so
 *                                     it lives on the role and nowhere else.
 *   tbluserprofilemaster.is_system    marks the nine canonical roles, so a tenant
 *                                     cannot delete one out from under the matrix.
 *
 * ─── CYCLE VALIDATION AND DEPTH BOUND (A5) ───────────────────────────────────
 * A reporting line is a graph and nothing stops it looping: A reports to B reports
 * to A. A cycle makes team-scope resolution non-terminating, so it must be
 * impossible to create rather than detected afterwards.
 *
 * The database cannot express "no cycles" — MySQL has no recursive CHECK — so the
 * guarantee is enforced in THREE places and stated here so no one assumes the
 * schema does it:
 *   1. Application validator on every write (see ReportingLineValidator).
 *   2. team_scope_depth, a tenant setting, default 1 = direct reports only (A5).
 *      Bounding depth means even an undetected cycle terminates.
 *   3. A periodic orphan/cycle check, the same shape as the polymorphic-integrity
 *      check for competency_kasba_item.
 *
 * Self-reference (a user managing themselves) is the degenerate one-node cycle and
 * is rejected by the same validator.
 *
 * ─── ROLLBACK ────────────────────────────────────────────────────────────────
 * down() drops exactly these columns. All are nullable and unread, so reverting is
 * inert and cannot lose data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbluser') && !Schema::hasColumn('tbluser', 'reporting_manager_id')) {
            Schema::table('tbluser', function (Blueprint $t) {
                // NULL = no manager. Legitimate: the org head has none, and A5's
                // no-manager ladder handles the rest.
                $t->unsignedBigInteger('reporting_manager_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('hrms_departments') && !Schema::hasColumn('hrms_departments', 'head_user_id')) {
            Schema::table('hrms_departments', function (Blueprint $t) {
                $t->unsignedBigInteger('head_user_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('tbluserprofilemaster')) {
            Schema::table('tbluserprofilemaster', function (Blueprint $t) {
                if (!Schema::hasColumn('tbluserprofilemaster', 'role_key')) {
                    // Stable machine name. The resolver keys on THIS, never on `name`.
                    $t->string('role_key', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('tbluserprofilemaster', 'data_scope')) {
                    $t->enum('data_scope', ['self', 'team', 'department', 'organization'])->nullable();
                }
                if (!Schema::hasColumn('tbluserprofilemaster', 'is_system')) {
                    $t->boolean('is_system')->default(false);
                }
            });
        }

        // Tenant setting: team scope depth. A5 — default 1, direct reports only.
        // Bounding depth is what makes an undetected cycle terminate.
        if (!Schema::hasTable('tenant_setting')) {
            Schema::create('tenant_setting', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->index();
                $t->string('setting_key', 96);
                $t->text('setting_value')->nullable();
                $t->timestamps();
                $t->unique(['sub_institute_id', 'setting_key'], 'uq_tenant_setting');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_setting');

        foreach (['tbluserprofilemaster' => ['role_key', 'data_scope', 'is_system'],
                  'hrms_departments'     => ['head_user_id'],
                  'tbluser'              => ['reporting_manager_id']] as $table => $cols) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $c) {
                if (Schema::hasColumn($table, $c)) {
                    Schema::table($table, function (Blueprint $t) use ($c) { $t->dropColumn($c); });
                }
            }
        }
    }
};
