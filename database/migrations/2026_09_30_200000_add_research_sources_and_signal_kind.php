<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Daily company research: keep a record of EVERY source a run retrieved (not only the ones
 * an opportunity ended up citing), and classify each opportunity as a Requirement /
 * Opportunity / Risk / Gap / Recommendation / Insight signal.
 *
 * Adds one table and one nullable column; nothing existing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_research_sources')) {
            Schema::create('g2g_research_sources', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('research_run_id');
                $t->string('url', 2000);
                $t->string('title', 500);
                $t->string('domain', 191)->nullable();
                $t->text('snippet')->nullable();
                $t->date('published_at')->nullable();
                $t->timestamp('retrieved_at');
                $t->boolean('page_fetched')->default(false);
                $t->boolean('cited')->default(false);   // referenced by a saved opportunity
                $t->timestamps();

                $t->index(['sub_institute_id', 'research_run_id'], 'g2g_research_sources_tenant_run');
            });
        }

        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            $t->string('signal_kind', 20)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            $t->dropColumn('signal_kind');
        });
        Schema::dropIfExists('g2g_research_sources');
    }
};
