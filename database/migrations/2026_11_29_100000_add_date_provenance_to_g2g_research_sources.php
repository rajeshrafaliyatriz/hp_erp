<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a source's publication date came from and how far to trust it (structured_data, meta_tag, time_element, visible_absolute,
 * visible_relative, search_provider). `published_at` already exists and keeps meaning "published", never "retrieved"; `retrieved_at`
 * is separate. Additive and nullable: existing rows keep an unknown provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_research_sources')) {
            return;
        }
        Schema::table('g2g_research_sources', function (Blueprint $t) {
            if (! Schema::hasColumn('g2g_research_sources', 'date_provenance')) {
                $t->string('date_provenance', 30)->nullable();
            }
            if (! Schema::hasColumn('g2g_research_sources', 'date_confidence')) {
                $t->string('date_confidence', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('g2g_research_sources')) {
            return;
        }
        Schema::table('g2g_research_sources', function (Blueprint $t) {
            foreach (['date_provenance', 'date_confidence'] as $col) {
                if (Schema::hasColumn('g2g_research_sources', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
