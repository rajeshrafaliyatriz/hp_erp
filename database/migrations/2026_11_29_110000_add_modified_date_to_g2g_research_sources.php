<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page's LAST-MODIFIED date (JSON-LD dateModified, article:modified_time, "Last updated", Last-Modified, a sitemap lastmod) is a different
 * fact from its publication date and is stored apart from it, never in `published_at`. It can only say the content is at least that old.
 * Additive and nullable: existing rows keep an unknown modification date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_research_sources')) {
            return;
        }
        Schema::table('g2g_research_sources', function (Blueprint $t) {
            if (! Schema::hasColumn('g2g_research_sources', 'modified_at')) {
                $t->date('modified_at')->nullable();
            }
            if (! Schema::hasColumn('g2g_research_sources', 'modified_provenance')) {
                $t->string('modified_provenance', 30)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('g2g_research_sources')) {
            return;
        }
        Schema::table('g2g_research_sources', function (Blueprint $t) {
            foreach (['modified_at', 'modified_provenance'] as $col) {
                if (Schema::hasColumn('g2g_research_sources', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
