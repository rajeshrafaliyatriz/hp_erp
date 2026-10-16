<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 2: automated lead scoring. Every lead
 * previously had only a manually-set `rating` (Hot/Warm/Cold, a human
 * judgment) - `lead_score` is a real computed number, maintained server-side
 * only (see App\Domain\Crm\Leads\LeadScorer), never accepted from a client
 * payload, same "server recomputes, never trusted from the client" rule the
 * rest of this module already follows for Opportunities' weighted revenue
 * and Invoices' balance due.
 *
 * `lead_score_basis` is the explainability trail (which active rule(s)
 * matched and how many points each contributed) - stored as JSON so the UI
 * can show "why" a lead scored what it did without re-running the rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->integer('lead_score')->default(0)->after('rating')->index();
            $table->text('lead_score_basis')->nullable()->after('lead_score');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn(['lead_score', 'lead_score_basis']);
        });
    }
};
