<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Automatic ingestion analysis: each finding now carries the full signal shape
 * (business impact, suggested next action, confidence). Three nullable columns added to
 * a table this feature created; no existing row or table is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g2g_ingestion_findings', function (Blueprint $t) {
            if (! Schema::hasColumn('g2g_ingestion_findings', 'business_impact')) {
                $t->text('business_impact')->nullable()->after('detail');
            }
            if (! Schema::hasColumn('g2g_ingestion_findings', 'suggested_action')) {
                $t->text('suggested_action')->nullable()->after('business_impact');
            }
            if (! Schema::hasColumn('g2g_ingestion_findings', 'confidence')) {
                $t->string('confidence', 10)->nullable()->after('priority');
            }
        });

        // Duplicate-submission guard: the same content/URL submitted twice within seconds
        // is the same ingestion, not a second one.
        Schema::table('g2g_ingestion_sources', function (Blueprint $t) {
            if (! Schema::hasColumn('g2g_ingestion_sources', 'content_hash')) {
                $t->char('content_hash', 64)->nullable()->after('size_bytes');
                $t->index(['sub_institute_id', 'content_hash'], 'g2g_ingest_src_tenant_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('g2g_ingestion_findings', function (Blueprint $t) {
            $t->dropColumn(['business_impact', 'suggested_action', 'confidence']);
        });
        Schema::table('g2g_ingestion_sources', function (Blueprint $t) {
            $t->dropIndex('g2g_ingest_src_tenant_hash');
            $t->dropColumn('content_hash');
        });
    }
};
