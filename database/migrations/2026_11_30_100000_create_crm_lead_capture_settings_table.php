<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 3: public web-to-lead capture - the
 * single biggest gap the product gap-analysis found ("no way for a prospect
 * to reach the CRM on their own"). One row per tenant.
 *
 * `public_key` identifies the tenant's form to an anonymous visitor -
 * deliberately NOT the tenant's own numeric id (that would let anyone probe
 * `/public/lead-capture/1`, `/2`, ... and learn which tenant ids exist).
 * `Str::random(40)` at creation time, re-rollable (PublicLeadCaptureAdmin
 * ::regenerateKey()) if a form link is ever compromised or simply retired.
 *
 * `default_assigned_to` is NOT NULL-enforced at the application layer (see
 * CrmLeadCaptureController::updateSettings()) whenever `is_enabled` is set
 * true - crm_leads.assigned_to is itself NOT NULL, so a public submission
 * that arrives with no round-robin rotation configured (App\Domain\Crm\
 * Leads\LeadAssigner) still needs somewhere to land. Nullable here only
 * because a tenant may configure everything else before turning it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_capture_settings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->unique();
            $table->string('public_key', 64)->unique();
            $table->boolean('is_enabled')->default(false);
            $table->string('default_lead_source', 191)->default('Website');
            $table->unsignedBigInteger('default_assigned_to')->nullable();

            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamp('last_submission_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_capture_settings');
    }
};
