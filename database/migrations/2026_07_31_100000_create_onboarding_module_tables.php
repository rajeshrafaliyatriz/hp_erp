<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding & Employee Lifecycle Center domain tables.
 *
 * Backs Talent Management -> Onboarding & Employee Lifecycle Center, which had
 * NO backend: a sweep of every route file, controller, model, migration and all
 * 266 database tables found no journey / preboarding task / onboarding document
 * / probation confirmation store wired to anything.
 *
 * TWO ORPHAN TABLES ARE ADOPTED HERE. `talent_onboarding_journeys` and
 * `talent_onboarding_tasks` already exist in the database with 0 rows, but have
 * NO migration in this repo, NO model, NO controller, NO route and zero code
 * references anywhere in the codebase - a skeleton somebody created by hand and
 * never used. This migration therefore:
 *   - creates them when they are absent (a fresh deploy), and
 *   - only tops up the missing columns when they are already present,
 * so it is safe to run against both a fresh database and the current dev one.
 * Nothing is dropped or renamed, and because nothing reads these tables today
 * no existing consumer can be affected.
 *
 * Existing tables were audited for reuse first and deliberately NOT repurposed:
 *   - staff_document              HR paperwork keyed to document_type_id, with no
 *                                 status lifecycle (requested -> sent -> received ->
 *                                 verified) and no journey link. It is a live table
 *                                 (8 rows); adding a status column would change the
 *                                 meaning of rows an existing HR screen already reads.
 *   - user_onboarding_status      product TOUR progress (Shepherd.js walkthroughs),
 *   + Onboarding_tour_details     nothing to do with employee onboarding.
 *   - talent_offboarding_cases    the exit end of the lifecycle.
 *   - s_performance_*             performance reviews; writing onboarding rows there
 *                                 would corrupt that module's cycle counts.
 *   - lms_assignments             learning assignments; already owned by the
 *                                 Competency module (source='competency').
 * Employees/managers/buddies (tbluser), departments (hrms_departments),
 * designations (org_designation), offers (talent_offers), applications
 * (talent_job_applications) and document types (document_type) ARE reused as-is;
 * no mirror tables were created for them.
 *
 * Conventions mirror 2026_07_30_100000_create_performance_module_tables:
 * bigIncrements PK, indexed unsignedBigInteger sub_institute_id, nullable indexed
 * audit columns, timestamps + softDeletes, string-based status enums and loose
 * joins with NO foreign-key constraints - the same style talent_* and
 * s_competency_* already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->upJourneys();
        $this->upTasks();

        // 3. Journey stages ---------------------------------------------------
        // The "Onboarding Journey Progress" timeline and the Onboarding Journey
        // tab. Seeded with 7 default stages when a journey is created, then
        // editable - stage dates are stored rather than derived, because
        // deriving them from joining_date would mean inventing fixed offsets.
        if (!Schema::hasTable('talent_onboarding_journey_stages')) {
            Schema::create('talent_onboarding_journey_stages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                // offer_accepted | preboarding | first_day | orientation |
                // team_integration | probation | confirmation
                $table->string('stage_key', 50)->index();
                $table->string('title', 191);
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                // pending | in_progress | completed | skipped
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 4. Documents --------------------------------------------------------
        // The sidebar Documents card: what was REQUESTED from the hire and where
        // each request stands. document_type_id points at the existing
        // `document_type` master so the two stay in one vocabulary.
        if (!Schema::hasTable('talent_onboarding_documents')) {
            Schema::create('talent_onboarding_documents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->unsignedBigInteger('document_type_id')->nullable()->index();
                $table->string('title', 191);
                $table->string('file_name', 191)->nullable();
                $table->string('file_path', 500)->nullable();
                // pending | sent | received | verified | rejected
                $table->string('status', 20)->default('pending')->index();
                $table->boolean('is_mandatory')->default(true);
                $table->date('due_date')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('verified_by')->nullable()->index();
                $table->text('remarks')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 5. Notes ------------------------------------------------------------
        // The sidebar Notes card. talent_onboarding_journeys.notes is a single
        // TEXT column; the card needs an authored, timestamped list.
        if (!Schema::hasTable('talent_onboarding_notes')) {
            Schema::create('talent_onboarding_notes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->text('note');
                // internal | shared - shared notes are visible to the new hire.
                $table->string('visibility', 20)->default('internal')->index();
                $table->string('author_name', 191)->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 6. Activity log -----------------------------------------------------
        // The Lifecycle Timeline tab and the module's audit trail. Same shape as
        // s_performance_activity_log so both feeds render identically.
        if (!Schema::hasTable('talent_onboarding_activity_log')) {
            Schema::create('talent_onboarding_activity_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();   // actor (tbluser.id)
                $table->string('actor_name', 191)->nullable();
                $table->string('action', 191)->index();
                $table->text('description')->nullable();
                // journey | task | stage | document | note | probation
                $table->string('subject_type', 100)->nullable()->index();
                $table->unsignedBigInteger('subject_id')->nullable()->index();
                $table->string('subject_name', 191)->nullable();
                // Field-level diff: [{field,label,old,new}].
                $table->longText('changes')->nullable();
                // Scopes the feed to one hire's lifecycle timeline.
                $table->unsignedBigInteger('journey_id')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    /**
     * talent_onboarding_journeys - one row per new hire's onboarding.
     *
     * Drives the profile sidebar, the breadcrumb journey code, the KPI cards and
     * the Probation & Confirmation tab.
     */
    private function upJourneys(): void
    {
        if (!Schema::hasTable('talent_onboarding_journeys')) {
            Schema::create('talent_onboarding_journeys', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id')->index();
                // Nullable: a preboarding hire has no tbluser row until day one.
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->unsignedBigInteger('offer_id')->nullable()->index();
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('location', 191)->nullable();
                $table->string('position', 191)->nullable();
                $table->date('joining_date')->nullable()->index();
                // preboarding | first_day | orientation | team_integration |
                // probation | confirmed | exited
                $table->string('stage', 30)->default('preboarding')->index();
                // not-started | in-progress | completed | on-hold | cancelled
                $table->string('status', 20)->default('not-started')->index();
                $table->unsignedBigInteger('buddy_id')->nullable()->index();
                $table->unsignedBigInteger('manager_id')->nullable()->index();
                $table->date('completed_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // The orphan table predates this migration and is missing everything the
        // screen needs beyond the basics. Each column is guarded so this is a
        // no-op on a database that already has it.
        Schema::table('talent_onboarding_journeys', function (Blueprint $table) {
            // The breadcrumb identifier the screen shows (OHB-2025-0456).
            if (!Schema::hasColumn('talent_onboarding_journeys', 'journey_code')) {
                $table->string('journey_code', 40)->nullable()->index()->after('sub_institute_id');
            }
            // A hire sourced from recruitment before they exist in tbluser.
            if (!Schema::hasColumn('talent_onboarding_journeys', 'application_id')) {
                $table->unsignedBigInteger('application_id')->nullable()->index()->after('offer_id');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'candidate_name')) {
                $table->string('candidate_name', 191)->nullable()->after('application_id');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'candidate_email')) {
                $table->string('candidate_email', 191)->nullable()->after('candidate_name');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'candidate_phone')) {
                $table->string('candidate_phone', 50)->nullable()->after('candidate_email');
            }
            // Probation & Confirmation tab. tbluser.probation_period_from/to hold
            // the same window for a confirmed employee; these carry it for a hire
            // who has no tbluser row yet, and record the decision itself, which
            // tbluser has nowhere to store.
            if (!Schema::hasColumn('talent_onboarding_journeys', 'probation_start')) {
                $table->date('probation_start')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'probation_end')) {
                $table->date('probation_end')->nullable()->index()->after('probation_start');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'extension_end')) {
                $table->date('extension_end')->nullable()->after('probation_end');
            }
            // pending | confirmed | extended | terminated
            if (!Schema::hasColumn('talent_onboarding_journeys', 'confirmation_status')) {
                $table->string('confirmation_status', 20)->default('pending')->index()->after('extension_end');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'confirmed_on')) {
                $table->date('confirmed_on')->nullable()->after('confirmation_status');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'confirmed_by')) {
                $table->unsignedBigInteger('confirmed_by')->nullable()->index()->after('confirmed_on');
            }
            if (!Schema::hasColumn('talent_onboarding_journeys', 'confirmation_notes')) {
                $table->text('confirmation_notes')->nullable()->after('confirmed_by');
            }
        });

        // employee_id was created NOT NULL on the orphan table, which blocks the
        // preboarding case the screen is built around (offer accepted, no tbluser
        // row yet). Safe to relax: the table holds 0 rows and nothing reads it.
        if (Schema::hasColumn('talent_onboarding_journeys', 'employee_id')) {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE `talent_onboarding_journeys` MODIFY `employee_id` BIGINT UNSIGNED NULL'
            );
        }
    }

    /**
     * talent_onboarding_tasks - the Preboarding Tasks table.
     *
     * `category` doubles as the workstream key, so the "Onboarding Integrations
     * & Tasks" cards are a rollup over this column rather than a second table:
     * documents | compliance | it | learning | payroll | benefits | personal.
     */
    private function upTasks(): void
    {
        if (!Schema::hasTable('talent_onboarding_tasks')) {
            Schema::create('talent_onboarding_tasks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('journey_id')->index();
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->string('title', 191);
                $table->text('description')->nullable();
                $table->string('category', 50)->nullable()->index();
                $table->unsignedBigInteger('owner_id')->nullable()->index();
                $table->date('due_date')->nullable()->index();
                // pending | in_progress | sent | completed | blocked
                $table->string('status', 20)->default('pending')->index();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('deleted_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('talent_onboarding_tasks', function (Blueprint $table) {
            // The screen shows owners like "HR Team" / "IT Team" that are not a
            // single tbluser row; owner_id stays authoritative when it is set.
            if (!Schema::hasColumn('talent_onboarding_tasks', 'owner_label')) {
                $table->string('owner_label', 100)->nullable()->after('owner_id');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_onboarding_activity_log');
        Schema::dropIfExists('talent_onboarding_notes');
        Schema::dropIfExists('talent_onboarding_documents');
        Schema::dropIfExists('talent_onboarding_journey_stages');

        // The two adopted tables are left in place - they predate this migration.
        // Only the columns this migration added are removed.
        if (Schema::hasTable('talent_onboarding_tasks')) {
            Schema::table('talent_onboarding_tasks', function (Blueprint $table) {
                if (Schema::hasColumn('talent_onboarding_tasks', 'owner_label')) {
                    $table->dropColumn('owner_label');
                }
            });
        }

        if (Schema::hasTable('talent_onboarding_journeys')) {
            Schema::table('talent_onboarding_journeys', function (Blueprint $table) {
                foreach ([
                    'journey_code', 'application_id', 'candidate_name', 'candidate_email',
                    'candidate_phone', 'probation_start', 'probation_end', 'extension_end',
                    'confirmation_status', 'confirmed_on', 'confirmed_by', 'confirmation_notes',
                ] as $column) {
                    if (Schema::hasColumn('talent_onboarding_journeys', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
