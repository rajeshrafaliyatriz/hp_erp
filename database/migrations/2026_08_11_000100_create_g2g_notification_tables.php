<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * X-06 — the notification service. Three tables, and one sentinel decision.
 *
 * Q-F1 AS BUILT: THE WORDING IS FIXED; ONLY THE TERMINOLOGY MOVES.
 *
 *   A tenant cannot rewrite "Your certification expires in 30 days" into whatever
 *   they like. They CAN say that what we call a `job_role` is called a "Position"
 *   in their house, and every notification, screen label and report heading then
 *   says Position. Fixed sentence, substitutable nouns.
 *
 *   That distinction is why `g2g_notification_template` has NO sub_institute_id
 *   and `g2g_terminology` does. It is enforced by the schema, not by a rule
 *   somebody has to remember.
 *
 * THE `locale` COLUMN IS BUILT NOW AND POPULATED WITH ONE VALUE.
 *   Only 'en' is seeded. The column exists because retrofitting a locale key onto
 *   a populated template table means rewriting every unique index and every
 *   lookup, and the cost of the column today is one varchar.
 *
 * SENTINEL: sub_institute_id = 0 MEANS "GLOBAL DEFAULT", NOT "NO TENANT".
 *   NULL was the obvious choice and is wrong here: MySQL treats NULLs as DISTINCT
 *   in a unique index, so `UNIQUE (sub_institute_id, term_key, locale)` would
 *   happily accept fifty global rows for the same term. 0 is a real value and the
 *   index holds. 0 is never a real tenant - EventRecorder already REFUSES it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. TERMINOLOGY ──────────────────────────────────────────────────
        // Tenant-substitutable nouns. Read by notifications AND by screen labels
        // and report headings - it is not a notification table that happens to be
        // reusable, it is a product-vocabulary table that notifications also use.
        if (!Schema::hasTable('g2g_terminology')) {
            Schema::create('g2g_terminology', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->default(0)
                    ->comment('0 = global default; any other value = a tenant override');
                $table->string('term_key', 64);
                $table->string('locale', 10)->default('en');
                $table->string('singular', 191);
                $table->string('plural', 191);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['sub_institute_id', 'term_key', 'locale'], 'g2g_term_unique');
                $table->index(['sub_institute_id', 'locale'], 'g2g_term_lookup');
            });
        }

        // ── 2. TEMPLATES ────────────────────────────────────────────────────
        // NO sub_institute_id, deliberately. See the header.
        if (!Schema::hasTable('g2g_notification_template')) {
            Schema::create('g2g_notification_template', function (Blueprint $table) {
                $table->id();
                $table->string('event_type', 96);
                $table->string('channel', 16);          // inapp | email
                $table->string('locale', 10)->default('en');
                $table->string('subject', 255);
                $table->text('body');
                $table->string('action_path', 255)->nullable()
                    ->comment('frontend path the recipient acts on; NULL means there is nowhere to go');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['event_type', 'channel', 'locale'], 'g2g_tpl_unique');
            });
        }

        // ── 3. THE INBOX ────────────────────────────────────────────────────
        // One row per (event, recipient, channel). An event with four recipients
        // is FOUR notifications and ONE g2g_event_delivery row - delivery is per
        // CONSUMER, notification is per PERSON, and conflating them is how you
        // send the second recipient nothing.
        if (!Schema::hasTable('g2g_notification')) {
            Schema::create('g2g_notification', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('event_id')->nullable();
                $table->string('event_type', 96);
                $table->string('channel', 16);
                $table->string('subject', 255);
                $table->text('body');
                $table->string('action_url', 255)->nullable();
                $table->string('recipient_reason', 64)
                    ->comment('WHY this person - the named-consumer test, recorded per row');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                // IDEMPOTENCY. A retried dispatch must not notify twice.
                $table->unique(['event_id', 'user_id', 'channel'], 'g2g_notif_once');
                $table->index(['sub_institute_id', 'user_id', 'read_at'], 'g2g_notif_inbox');
            });
        }

        $this->seedTerminology();
        $this->seedTemplates();
    }

    /**
     * DESIGN DATA, NOT TENANT DATA — the same argument that puts EventCatalogue in
     * code rather than in a table. These rows are the product's default vocabulary
     * and its fixed wording; a tenant adds overrides beside them, never edits them.
     */
    private function seedTerminology(): void
    {
        $terms = [
            ['job_role',         'Job Role',         'Job Roles'],
            ['competency',       'Competency',       'Competencies'],
            ['skill',            'Skill',            'Skills'],
            ['employee',         'Employee',         'Employees'],
            ['department',       'Department',       'Departments'],
            ['assessment',       'Assessment',       'Assessments'],
            ['certification',    'Certification',    'Certifications'],
            ['development_plan', 'Development Plan', 'Development Plans'],
            ['task',             'Task',             'Tasks'],
            ['manager',          'Manager',          'Managers'],
            ['gap',              'Gap',              'Gaps'],
            ['course',           'Course',           'Courses'],
        ];

        foreach ($terms as [$key, $singular, $plural]) {
            DB::table('g2g_terminology')->updateOrInsert(
                ['sub_institute_id' => 0, 'term_key' => $key, 'locale' => 'en'],
                ['singular' => $singular, 'plural' => $plural, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    /**
     * SIX EVENTS, NOT NINE. The three that are missing failed the named-consumer
     * test on measured grounds and are recorded in EventCatalogue::NOT_NOTIFIED
     * with their triggers. A template seeded for an event nobody can act on is
     * exactly the "slower log" the catalogue exists to prevent.
     */
    private function seedTemplates(): void
    {
        $t = [
            // event_type, action_path, subject, body
            ['task.rejected', '/tasks/{payload.entity_id}',
                'Your {term:task} was sent back',
                "\"{payload.task_title}\" was not approved and has been returned to you.\n\nReason given: {payload.approve_remarks}\n\nOpen the {term:task} to make the changes and resubmit it."],

            ['assessment.completed', '/competency/my-capability',
                'Your {term:assessment} result is ready',
                "An {term:assessment} covering {payload.competency_name} has been completed.\n\nYour capability profile and any {term:gap|plural} have been updated. Open My Capability to see where you now stand."],

            ['certification.expiring', '/competency/my-capability',
                'Your {term:certification} expires on {payload.expiry_date}',
                "{payload.certification_name} expires on {payload.expiry_date}.\n\nRenew it before that date to keep it valid. If you have already renewed, no action is needed."],

            ['development_plan.approved', '/competency/development-plan/{payload.entity_id}',
                'Your {term:development_plan} was approved',
                "Your {term:development_plan} for {payload.competency_name} has been approved.\n\nThe {term:course|plural} on it are now yours to start."],

            ['employee.offboarded', '/talent/offboarding/{payload.entity_id}',
                'Handover needed: {payload.employee_name} has left',
                "{payload.employee_name} has been offboarded.\n\nSystem access has been revoked and open {term:task|plural} have been reassigned automatically. Confirm the handover is complete on the offboarding case."],

            ['rights.changed', '/settings/my-access',
                'Your access has changed',
                "Your permissions were changed by {payload.actor_name}.\n\nIf you were expecting this, nothing further is needed. IF YOU WERE NOT, report it to your administrator - this notice exists so that a change nobody made cannot go unnoticed."],
        ];

        foreach ($t as [$event, $path, $subject, $body]) {
            foreach (['inapp', 'email'] as $channel) {
                DB::table('g2g_notification_template')->updateOrInsert(
                    ['event_type' => $event, 'channel' => $channel, 'locale' => 'en'],
                    [
                        'subject'     => $subject,
                        'body'        => $body,
                        'action_path' => $path,
                        'is_active'   => true,
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ]
                );
            }
        }
    }

    /**
     * DELIBERATELY EMPTY.
     *
     * The standing no-deletion rule outranks migration convention here. Rolling
     * this back would drop `g2g_notification`, and those rows are the record that
     * a real person was really told something - the same argument that makes a
     * reactor's dispatch ledger permanent. If these tables must go, that is a
     * decision taken with R8's checklist in hand, not a side effect of a rollback.
     */
    public function down(): void
    {
        // no-op by design; see the docblock.
    }
};
