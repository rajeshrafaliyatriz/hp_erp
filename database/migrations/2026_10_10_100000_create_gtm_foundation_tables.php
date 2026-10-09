<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GTM & Revenue workspace - foundation tables.
 *
 * WHAT THIS DELIBERATELY DOES NOT CREATE
 *
 * Discovery already exists: g2g_companies (who), g2g_company_opportunities (why now, with
 * sources), g2g_research_runs / g2g_research_sources (provenance), g2g_product_profiles
 * (the ICP), g2g_product_offers and g2g_partners. A GTM account points at a g2g_company
 * through `company_id` instead of copying it, so a signal found by research is attached
 * to the account automatically and nothing is entered twice.
 *
 * WHAT IS NEW, AND WHY
 *
 *  gtm_accounts    the working record: owner, stage, ICP fit. g2g_companies has neither.
 *  gtm_contacts    nothing in G2G holds people at a prospect.
 *  gtm_activities  one timeline per account / contact / deal. This is the only place a
 *                  "touch" is recorded, so outreach metrics are counted, never estimated.
 *  gtm_playbooks   role playbooks, methodologies, industry packs, templates and workflow
 *                  definitions in ONE table (`kind`). sub_institute_id NULL = platform
 *                  default, a tenant row with the same slug overrides it.
 *  gtm_analyses    every AI result (account research, ICP fit, deal coaching...) with the
 *                  provider/model that produced it and `is_estimate`, so the dashboard can
 *                  always tell a measured number from a model's opinion.
 *
 * Every tenant table carries sub_institute_id and is only read or written with it.
 * Nothing here alters an existing table. JSON is stored as longText (MariaDB 10.1 on
 * live has no JSON type) and indexed prefixes stay under the 767-byte utf8mb4 limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gtm_accounts')) {
            Schema::create('gtm_accounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('company_id')->nullable();        // g2g_companies.id
                $t->string('name', 191);
                $t->string('domain', 191)->nullable();
                $t->string('website', 255)->nullable();
                $t->string('industry', 150)->nullable();
                $t->string('employee_range', 50)->nullable();
                $t->string('location', 191)->nullable();
                // target | engaged | opportunity | customer | churned | disqualified
                $t->string('stage', 20)->default('target');
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->unsignedTinyInteger('icp_fit_score')->nullable();    // 0-100, an ESTIMATE
                $t->longText('icp_fit_basis')->nullable();               // JSON: what drove the score
                $t->timestamp('icp_scored_at')->nullable();
                $t->string('source', 20)->default('manual');             // manual | research | import
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->unique(['sub_institute_id', 'domain'], 'gtm_accounts_tenant_domain_unique');
                $t->index(['sub_institute_id', 'stage'], 'gtm_accounts_tenant_stage_idx');
                $t->index(['sub_institute_id', 'company_id'], 'gtm_accounts_tenant_company_idx');
                $t->index(['sub_institute_id', 'owner_user_id'], 'gtm_accounts_tenant_owner_idx');
            });
        }

        if (! Schema::hasTable('gtm_contacts')) {
            Schema::create('gtm_contacts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('account_id');
                $t->string('full_name', 191);
                $t->string('title', 191)->nullable();
                $t->string('email', 191)->nullable();
                $t->string('phone', 50)->nullable();
                $t->string('linkedin_url', 255)->nullable();
                // champion | economic_buyer | decision_maker | influencer | user | blocker
                $t->string('role_in_deal', 30)->nullable();
                // active | bounced | unsubscribed | do_not_contact
                $t->string('status', 20)->default('active');
                $t->string('source', 20)->default('manual');             // manual | import | research
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['sub_institute_id', 'account_id'], 'gtm_contacts_tenant_account_idx');
                $t->index(['sub_institute_id', 'email'], 'gtm_contacts_tenant_email_idx');
            });
        }

        if (! Schema::hasTable('gtm_activities')) {
            Schema::create('gtm_activities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('account_id')->nullable();
                $t->unsignedBigInteger('contact_id')->nullable();
                $t->unsignedBigInteger('deal_id')->nullable();
                // note | email | call | meeting | linkedin | task | system
                $t->string('type', 20);
                $t->string('direction', 10)->nullable();                 // inbound | outbound
                $t->string('subject', 255)->nullable();
                $t->longText('body')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->longText('metadata')->nullable();                    // JSON
                $t->timestamps();

                $t->index(['sub_institute_id', 'account_id', 'occurred_at'], 'gtm_activities_tenant_account_idx');
                $t->index(['sub_institute_id', 'type', 'occurred_at'], 'gtm_activities_tenant_type_idx');
            });
        }

        if (! Schema::hasTable('gtm_playbooks')) {
            Schema::create('gtm_playbooks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id')->nullable();  // NULL = platform default
                // role_playbook | methodology | industry_pack | template | workflow
                $t->string('kind', 20);
                // sdr | ae | marketing | revops | csm | all
                $t->string('role', 20)->default('all');
                $t->string('stage', 30)->nullable();                     // prospecting, discovery, renewal...
                $t->string('slug', 100);
                $t->string('title', 191);
                $t->text('description')->nullable();
                $t->longText('body')->nullable();                        // the instruction the model receives
                $t->longText('inputs')->nullable();                      // JSON: data the run requires
                $t->longText('output_schema')->nullable();               // JSON: shape the model must return
                $t->longText('definition')->nullable();                  // JSON: rubric dimensions / workflow steps
                $t->string('status', 10)->default('active');             // active | draft | archived
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_system')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->unique(['sub_institute_id', 'slug'], 'gtm_playbooks_tenant_slug_unique');
                $t->index(['kind', 'role', 'status'], 'gtm_playbooks_kind_role_idx');
            });
        }

        if (! Schema::hasTable('gtm_analyses')) {
            Schema::create('gtm_analyses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                // account_research | icp_fit | outreach_draft | deal_coach | pipeline_review ...
                $t->string('kind', 40);
                $t->string('subject_type', 30)->nullable();              // account | contact | deal | pipeline | customer
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->unsignedBigInteger('playbook_id')->nullable();
                $t->text('input_summary')->nullable();
                $t->longText('result')->nullable();                      // JSON
                $t->longText('sources')->nullable();                     // JSON: URLs / row refs the result rests on
                $t->boolean('is_estimate')->default(true);
                $t->string('status', 10)->default('success');            // success | failed
                $t->string('error_code', 60)->nullable();
                $t->string('provider', 60)->nullable();
                $t->string('model', 100)->nullable();
                $t->unsignedInteger('tokens')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'kind', 'created_at'], 'gtm_analyses_tenant_kind_idx');
                $t->index(['sub_institute_id', 'subject_type', 'subject_id'], 'gtm_analyses_tenant_subject_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['gtm_analyses', 'gtm_playbooks', 'gtm_activities', 'gtm_contacts', 'gtm_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
