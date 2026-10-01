<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * G2G Foundation Data: Product Portfolio, Partner Network, and Opportunity Matching.
 *
 * Tables created:
 *  - g2g_product_offers: offer-level catalog (25 baseline offers across G2G, EB, Scholar K-12, HE, Bundles)
 *  - g2g_partners: partner registry with delivery capability, coverage, capacity, and authorizations
 *  - g2g_opportunity_matches: records and human reviews of signal-to-offer and partner matches
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_product_offers')) {
            Schema::create('g2g_product_offers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id')->nullable(); // null = shared foundation offer
                $t->string('offer_id', 50);
                $t->string('name', 191);
                $t->string('parent_product', 50);
                $t->text('modules_components')->nullable();
                $t->text('what_it_does')->nullable();
                $t->json('needs_solved')->nullable();         // array of taxonomy Need codes (N01..N20)
                $t->json('primary_segments')->nullable();     // array of taxonomy Segment codes
                $t->json('trigger_signals')->nullable();      // array of taxonomy Signal codes
                $t->string('deployment_model', 100)->nullable();
                $t->string('readiness_status', 100);
                $t->boolean('readiness_confirmed')->default(false);
                $t->string('typical_deal_band', 50)->nullable();
                $t->string('pricing_model', 191)->nullable();
                $t->string('implementation_effort', 100)->nullable();
                $t->string('delivery_owner', 100)->nullable();
                $t->json('bundles_with')->nullable();         // array of offer IDs
                $t->text('proof_points')->nullable();
                $t->boolean('partner_sellable')->default(false);
                $t->text('notes')->nullable();
                $t->boolean('is_user_edited')->default(false); // protects user edits on subsequent imports
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'offer_id'], 'g2g_offers_tenant_offer_id');
                $t->index(['sub_institute_id', 'parent_product'], 'g2g_offers_tenant_product');
                $t->index(['sub_institute_id', 'partner_sellable'], 'g2g_offers_tenant_sellable');
            });
        }

        if (! Schema::hasTable('g2g_partners')) {
            Schema::create('g2g_partners', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id')->nullable();
                $t->string('partner_id', 50);
                $t->string('partner_name', 191);
                $t->string('partner_type', 100);
                $t->string('hq_state', 100)->nullable();
                $t->json('states_covered')->nullable();       // array of states/regions or "All India"
                $t->json('segments_covered')->nullable();     // array of Segment codes
                $t->json('needs_addressed')->nullable();      // array of Need codes
                $t->json('procurement_routes')->nullable();   // array of procurement route codes
                $t->json('offers_authorized')->nullable();    // array of offer IDs partner is authorized to sell
                $t->json('empanelments')->nullable();         // e.g. GeM seller, state portal
                $t->json('certifications')->nullable();       // e.g. ISO 9001, CMMI
                $t->text('key_buyer_relationships')->nullable();
                $t->string('delivery_capability', 100)->nullable();
                $t->unsignedSmallInteger('max_concurrent_deals')->default(1);
                $t->unsignedSmallInteger('active_deals_now')->default(0);
                $t->integer('capacity_available')->default(1);
                $t->string('sales_contact_name', 191)->nullable();
                $t->string('contact_email', 191)->nullable();
                $t->string('contact_phone', 100)->nullable();
                $t->string('preferred_channel', 50)->nullable();
                $t->string('commercial_model', 100)->nullable();
                $t->decimal('referral_margin_pct', 5, 2)->nullable();
                $t->boolean('deal_registration_agreed')->default(false);
                $t->text('conflicts')->nullable();
                $t->unsignedInteger('leads_received')->default(0);
                $t->unsignedInteger('acknowledged_within_sla')->default(0);
                $t->unsignedInteger('deals_won')->default(0);
                $t->string('partner_status', 50)->default('Prospect'); // Prospect, Onboarding, Active, Paused, Exited
                $t->decimal('conversion_rate', 5, 4)->nullable();
                $t->unsignedSmallInteger('avg_days_to_first_meeting')->nullable();
                $t->text('notes')->nullable();
                $t->boolean('is_example')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'partner_id'], 'g2g_partners_tenant_partner_id');
                $t->index(['sub_institute_id', 'partner_status'], 'g2g_partners_tenant_status');
            });
        }

        if (! Schema::hasTable('g2g_opportunity_matches')) {
            Schema::create('g2g_opportunity_matches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('signal_type', 30);                // signal | opportunity | ingestion_finding
                $t->unsignedBigInteger('signal_id');
                $t->string('offer_id', 50);
                $t->string('partner_id', 50)->nullable();
                $t->string('match_status', 20)->default('Matched'); // Matched | Reviewed | Follow-up | Dismissed
                $t->text('review_notes')->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'signal_type', 'signal_id'], 'g2g_opp_matches_tenant_signal');
                $t->index(['sub_institute_id', 'offer_id'], 'g2g_opp_matches_tenant_offer');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_opportunity_matches');
        Schema::dropIfExists('g2g_partners');
        Schema::dropIfExists('g2g_product_offers');
    }
};

