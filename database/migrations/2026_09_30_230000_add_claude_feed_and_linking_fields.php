<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            $t->longText('confirmed_facts')->nullable()->after('sources');
            $t->longText('unverified_claims')->nullable()->after('confirmed_facts');
            $t->string('urgency', 50)->nullable()->after('priority');
            $t->string('feed_section', 50)->nullable()->after('urgency');
            $t->string('relevant_offer_id', 50)->nullable()->after('product_fit');
            $t->string('relevant_offer_name', 191)->nullable()->after('relevant_offer_id');
            $t->unsignedBigInteger('ingestion_source_id')->nullable()->after('research_run_id');
            $t->index(['sub_institute_id', 'ingestion_source_id'], 'g2g_opp_ingest_source_idx');
            $t->text('review_notes')->nullable()->after('review_status');
        });

        Schema::table('g2g_ingestion_sources', function (Blueprint $t) {
            $t->longText('discovered_news')->nullable()->after('truncated');
            $t->text('extracted_summary')->nullable()->after('discovered_news');
            $t->longText('identified_entities')->nullable()->after('extracted_summary');
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
