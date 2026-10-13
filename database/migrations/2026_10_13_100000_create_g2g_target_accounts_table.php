<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Target accounts: the named organisations research watches (for Scholar K-12: the schools in the CRM list).
 *
 * Search alone cannot tell us a school's campuses, students, board or fee level, so those static facts live
 * here (and are filled in as we learn them) while research supplies the live events. ADDITIVE ONLY: a new
 * table, nothing else is touched. Contact phone numbers and emails are NOT copied from the CRM; `crm_ref`
 * (the CRM lead number) lets a person find the contact there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_target_accounts')) {
            return;
        }

        Schema::create('g2g_target_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('pack', 50);                              // ICP pack, e.g. scholar_k12
            $t->unsignedBigInteger('company_id')->nullable();    // g2g_companies.id, so signals attach to the same organisation
            $t->string('account_key', 191);                      // normalised name | city: one row per school
            $t->string('name', 191);
            $t->string('city', 100)->nullable();
            $t->string('state', 100)->nullable();
            $t->string('board', 40)->nullable();
            $t->string('level', 60)->nullable();                 // as stated in the CRM, e.g. Senior Secondary Level
            $t->string('website', 500)->nullable();
            $t->string('crm_ref', 50)->nullable();               // CRM lead number
            $t->string('crm_owner', 100)->nullable();
            $t->string('contact_name', 191)->nullable();
            $t->longText('attributes')->nullable();              // JSON: campuses, students, fee_tier, systems, management, medium ...
            $t->string('icp_status', 20)->default('unscored');   // candidate | low_fit | unscored | disqualified
            $t->char('icp_tier', 1)->nullable();                 // A | B | C, only when enough facts are known
            $t->unsignedSmallInteger('icp_fit_pct')->nullable();
            $t->unsignedTinyInteger('icp_known_share')->nullable();
            $t->longText('icp_notes')->nullable();               // JSON: what was known, unknown, why disqualified
            $t->unsignedTinyInteger('watch_priority')->default(3); // 1 = watch first ... 9 = never
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('last_signal_at')->nullable();
            $t->timestamps();

            $t->unique(['sub_institute_id', 'pack', 'account_key'], 'g2g_target_accounts_unique');
            $t->index(['sub_institute_id', 'pack', 'watch_priority', 'last_checked_at'], 'g2g_target_accounts_watch');
            $t->index('company_id', 'g2g_target_accounts_company');
        });
    }

    /** Dropping would erase the account list and its history; leave it. */
    public function down(): void
    {
    }
};
