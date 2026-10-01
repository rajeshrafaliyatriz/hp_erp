<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Company Opportunity Intelligence + Manual Ingestion Engine.
 *
 * Two independent workflows, deliberately in separate tables so provenance never
 * mixes: a document someone uploaded can never look like an independently verified
 * public company event.
 *
 *  Daily research : product_profiles -> research_runs -> companies -> company_opportunities
 *  Ingestion      : ingestion_sources -> ingestion_analyses -> ingestion_findings
 *
 * Every table carries sub_institute_id (the organisation) and is only ever read or
 * written with it. Nothing here alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_product_profiles')) {
            Schema::create('g2g_product_profiles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id')->unique();
                $t->string('product_name', 191)->nullable();
                $t->text('description')->nullable();
                $t->text('problems_solved')->nullable();
                $t->text('features')->nullable();
                $t->longText('target_industries')->nullable();
                $t->longText('target_company_types')->nullable();
                $t->string('target_company_size', 100)->nullable();
                $t->longText('target_markets')->nullable();
                $t->text('ideal_customer_profile')->nullable();
                $t->longText('keywords')->nullable();
                $t->longText('excluded')->nullable();
                $t->longText('competitors')->nullable();
                $t->boolean('research_enabled')->default(false);
                $t->string('research_frequency', 10)->default('daily');   // daily | weekdays | weekly
                $t->string('schedule_time', 5)->nullable();                // HH:MM, null = config default
                $t->unsignedSmallInteger('recency_days')->default(30);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('g2g_research_runs')) {
            Schema::create('g2g_research_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->date('report_date');
                $t->string('trigger', 20);                 // scheduled | manual
                $t->string('status', 20);                  // running | success | partial | failed | skipped
                $t->unsignedBigInteger('triggered_by')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->unsignedInteger('duration_ms')->nullable();
                $t->unsignedSmallInteger('queries_run')->default(0);
                $t->unsignedSmallInteger('queries_failed')->default(0);
                $t->unsignedSmallInteger('sources_found')->default(0);
                $t->unsignedSmallInteger('companies_researched')->default(0);
                $t->unsignedSmallInteger('opportunities_qualified')->default(0);
                $t->unsignedSmallInteger('opportunities_new')->default(0);
                $t->unsignedSmallInteger('opportunities_rejected')->default(0);
                $t->string('search_provider', 40)->nullable();
                $t->string('ai_provider', 40)->nullable();
                $t->string('ai_model', 120)->nullable();
                $t->string('error_code', 40)->nullable();
                $t->string('error_message', 500)->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'report_date'], 'g2g_research_runs_tenant_date');
                $t->index(['sub_institute_id', 'status'], 'g2g_research_runs_tenant_status');
            });
        }

        if (! Schema::hasTable('g2g_companies')) {
            Schema::create('g2g_companies', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('name', 191);
                $t->string('normalized_name', 191);
                $t->string('website', 500)->nullable();
                $t->string('domain', 191)->nullable();
                $t->string('industry', 191)->nullable();
                $t->string('location', 191)->nullable();
                $t->timestamp('first_seen_at')->nullable();
                $t->timestamp('last_verified_at')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'normalized_name'], 'g2g_companies_tenant_name');
                $t->index(['sub_institute_id', 'domain'], 'g2g_companies_tenant_domain');
            });
        }

        if (! Schema::hasTable('g2g_company_opportunities')) {
            Schema::create('g2g_company_opportunities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('company_id');
                $t->unsignedBigInteger('research_run_id');
                $t->string('product_name', 191)->nullable();
                $t->string('title', 191);
                $t->string('category', 40);
                $t->text('observed_event');
                $t->text('why_indicates_need');
                $t->text('product_fit');
                $t->text('recommended_action');
                $t->string('priority', 10);                // High | Medium | Low
                $t->string('qualification', 40);           // see OpportunityQualification
                $t->string('confidence', 10);              // High | Medium | Low
                $t->string('review_status', 12)->default('New'); // New | Reviewed | Follow-up | Dismissed
                $t->longText('sources');                       // [{url,title,published_at,excerpt,retrieved_at}]
                $t->date('source_published_at')->nullable();
                $t->date('event_date')->nullable();
                $t->char('fingerprint', 64);
                $t->timestamp('first_discovered_at');
                $t->timestamp('last_verified_at');
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'fingerprint'], 'g2g_opps_tenant_fingerprint');
                $t->index(['sub_institute_id', 'review_status', 'priority'], 'g2g_opps_tenant_review_priority');
                $t->index(['sub_institute_id', 'research_run_id'], 'g2g_opps_tenant_run');
                $t->index(['sub_institute_id', 'company_id'], 'g2g_opps_tenant_company');
            });
        }

        if (! Schema::hasTable('g2g_ingestion_sources')) {
            Schema::create('g2g_ingestion_sources', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('type', 10);                    // file | url
                $t->string('name', 255);
                $t->string('original_filename', 255)->nullable();
                $t->string('mime', 120)->nullable();
                $t->unsignedBigInteger('size_bytes')->nullable();
                $t->string('storage_path', 500)->nullable(); // private disk, never returned by the API
                $t->string('url', 2000)->nullable();
                $t->string('page_title', 500)->nullable();
                $t->string('status', 12);                  // ready | failed
                $t->string('error_message', 500)->nullable();
                $t->longText('segments')->nullable();      // JSON [{ref,text}]
                $t->unsignedInteger('char_count')->default(0);
                $t->boolean('truncated')->default(false);
                $t->timestamp('retrieved_at')->nullable();
                $t->timestamp('last_analyzed_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'created_at'], 'g2g_ingest_src_tenant_created');
            });
        }

        if (! Schema::hasTable('g2g_ingestion_analyses')) {
            Schema::create('g2g_ingestion_analyses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('source_id');
                $t->string('status', 12);                  // running | success | partial | failed
                $t->unsignedBigInteger('triggered_by')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->unsignedInteger('duration_ms')->nullable();
                $t->unsignedSmallInteger('findings_count')->default(0);
                $t->unsignedSmallInteger('findings_rejected')->default(0);
                $t->string('ai_provider', 40)->nullable();
                $t->string('ai_model', 120)->nullable();
                $t->string('error_code', 40)->nullable();
                $t->string('error_message', 500)->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'source_id'], 'g2g_ingest_ana_tenant_source');
            });
        }

        if (! Schema::hasTable('g2g_ingestion_findings')) {
            Schema::create('g2g_ingestion_findings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('source_id');
                $t->unsignedBigInteger('analysis_id');
                $t->string('kind', 20);                    // requirement | opportunity | risk | gap | recommendation | insight | company_info
                $t->string('title', 191);
                $t->text('detail');
                $t->string('priority', 10)->nullable();
                $t->longText('evidence');                      // [{ref, quote}] quotes verified against the source
                $t->string('review_status', 12)->default('New');
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'source_id'], 'g2g_ingest_find_tenant_source');
                $t->index(['sub_institute_id', 'analysis_id'], 'g2g_ingest_find_tenant_analysis');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'g2g_ingestion_findings', 'g2g_ingestion_analyses', 'g2g_ingestion_sources',
            'g2g_company_opportunities', 'g2g_companies', 'g2g_research_runs', 'g2g_product_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
