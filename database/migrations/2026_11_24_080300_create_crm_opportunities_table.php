<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opportunities - the legacy CRM's "Potentials" module, relabeled (same
 * relabel pattern as Organizations/Accounts).
 *
 * `organization_id` matters more here than it looks: legacy's plainly-named
 * `related_to` column IS the parent Organization (confirmed:
 * `LEFT JOIN vtiger_account on vtiger_potential.related_to=vtiger_account.accountid`,
 * UI label "Organization Name"). Without this FK, an Opportunity could not
 * be cross-linked to its Organization - the decision this migration exists
 * to serve, instead of duplicating Organizations under a new Sales menu.
 *
 * `converted_from_gtm_deal_id` is the one deliberate connection point to the
 * separate GTM & Revenue module's AI-assisted deal pipeline (`gtm_deals`) -
 * populated only when a GTM deal is won and converted, never otherwise.
 * GTM Deals and CRM Opportunities are kept fully separate by design; see
 * the plan's Decisions section for why.
 *
 * Dropped from legacy: `quotationref` (a redundant scalar reverse-pointer
 * into the real Opportunity->Quotes one-to-many; a cached "last quote" value
 * is a staleness bug waiting to happen - a related-list panel replaces it).
 * "Weighted Revenue" is computed (amount x probability / 100) in the
 * response mapper, never a stored column - matches legacy, where it is
 * never a schema column there either.
 *
 * Explicitly deferred, not silently dropped: `runtimefee`, `outcomeanalysis`,
 * `partnercontact`, `remarks`, `followupdate`, `productversion`,
 * `typeofrevenue` - present in the legacy schema but absent from that
 * module's own UI label-override file, so there is no positive evidence any
 * of these were ever surfaced to a real user (and no DB access to verify
 * definitively either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('opportunity_no', 100)->unique();
            $table->string('name', 191);
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();

            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->date('closing_date')->nullable();
            $table->string('sales_stage', 100)->nullable()->index();
            $table->decimal('probability', 5, 2)->nullable();
            $table->string('lead_source', 100)->nullable();
            $table->string('potential_type', 50)->nullable();
            $table->text('next_step')->nullable();
            $table->string('forecast_category', 50)->nullable();
            $table->text('description')->nullable();

            $table->unsignedBigInteger('converted_from_gtm_deal_id')->nullable()->index();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_opportunities', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('crm_campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunities');
    }
};
