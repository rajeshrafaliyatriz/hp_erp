<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            if (! Schema::hasColumn('g2g_company_opportunities', 'confirmed_facts')) {
                $t->json('confirmed_facts')->nullable()->after('sources');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'unverified_claims')) {
                $t->json('unverified_claims')->nullable()->after('confirmed_facts');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'urgency')) {
                $t->string('urgency', 50)->nullable()->after('priority');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'feed_section')) {
                $t->string('feed_section', 50)->nullable()->after('urgency');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'relevant_offer_id')) {
                $t->string('relevant_offer_id', 50)->nullable()->after('product_fit');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'relevant_offer_name')) {
                $t->string('relevant_offer_name', 191)->nullable()->after('relevant_offer_id');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'ingestion_source_id')) {
                $t->unsignedBigInteger('ingestion_source_id')->nullable()->after('research_run_id');
                $t->index(['sub_institute_id', 'ingestion_source_id'], 'g2g_opp_ingest_source_idx');
            }
            if (! Schema::hasColumn('g2g_company_opportunities', 'review_notes')) {
                $t->text('review_notes')->nullable()->after('review_status');
            }
        });

        Schema::table('g2g_ingestion_sources', function (Blueprint $t) {
            if (! Schema::hasColumn('g2g_ingestion_sources', 'discovered_news')) {
                $t->json('discovered_news')->nullable()->after('truncated');
            }
            if (! Schema::hasColumn('g2g_ingestion_sources', 'extracted_summary')) {
                $t->text('extracted_summary')->nullable()->after('discovered_news');
            }
            if (! Schema::hasColumn('g2g_ingestion_sources', 'identified_entities')) {
                $t->json('identified_entities')->nullable()->after('extracted_summary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            $cols = ['confirmed_facts', 'unverified_claims', 'urgency', 'feed_section', 'relevant_offer_id', 'relevant_offer_name', 'ingestion_source_id', 'review_notes'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('g2g_company_opportunities', $col)) {
                    $t->dropColumn($col);
                }
            }
        });

        Schema::table('g2g_ingestion_sources', function (Blueprint $t) {
            $cols = ['discovered_news', 'extracted_summary', 'identified_entities'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('g2g_ingestion_sources', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
