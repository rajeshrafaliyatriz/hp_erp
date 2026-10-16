<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 2: admin-configurable lead scoring
 * rules and round-robin assignment. All three tables are per-tenant config,
 * not business records - no soft deletes, same convention crm_tax_rates
 * uses for the same reason.
 *
 * `crm_lead_scoring_rules` - a rule-based (not predictive/AI) scoring
 * engine, deliberately: App\Domain\Crm\Leads\LeadScorer::ALLOWED_FIELDS caps
 * `field_key` to a known-safe allow-list (never an arbitrary client-supplied
 * column name).
 *
 * `crm_lead_assignment_settings` (one row per tenant) + `crm_lead_
 * assignment_rotation_members` (one row per person in the rotation, tried in
 * `sort_order`) together implement round-robin: `last_assigned_user_id` is
 * the only state the rotation needs to resume correctly, and
 * App\Domain\Crm\Leads\LeadAssigner locks this settings row for the
 * duration of the assignment so two leads created at the same moment can
 * never be handed to the same person twice in a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_scoring_rules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('field_key', 50);
            $table->string('operator', 20);
            $table->string('value', 191)->nullable();
            $table->integer('points');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
        });

        Schema::create('crm_lead_assignment_settings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();

            $table->timestamps();
        });

        Schema::create('crm_lead_assignment_rotation_members', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['sub_institute_id', 'user_id'], 'crm_lead_rotation_members_tenant_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_assignment_rotation_members');
        Schema::dropIfExists('crm_lead_assignment_settings');
        Schema::dropIfExists('crm_lead_scoring_rules');
    }
};
