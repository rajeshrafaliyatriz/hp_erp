<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GTM marketing: segments, campaigns and their members.
 *
 *   php artisan migrate --path=database/migrations/2026_10_11_110000_gtm_marketing.php
 *
 * A SEGMENT is a saved filter over accounts or contacts (criteria JSON, validated against a fixed
 * set of keys). It is re-evaluated on read, so it never goes stale. A CAMPAIGN targets accounts
 * (account-based marketing) and optionally specific contacts; membership is explicit rows. Campaign
 * results are never stored: replies, bounces, conversions are COUNTED from outreach messages,
 * activities and deals on each read, so they cannot drift from the records they describe.
 *
 * gtm_campaign_members.contact_id is 0 (not NULL) for an account-level target so the unique index
 * works: NULLs are distinct in a MariaDB UNIQUE key and would allow duplicates.
 * gtm_outreach_messages.campaign_id ties an email to the campaign it belongs to.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if (! $this->has('gtm_segments')) {
            Schema::create('gtm_segments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('name', 191);
                $t->string('kind', 10)->default('account');              // account | contact
                $t->text('description')->nullable();
                $t->longText('criteria');                                 // JSON
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['sub_institute_id', 'kind'], 'gtm_segments_tenant_kind_idx');
            });
        }

        if (! $this->has('gtm_campaigns')) {
            Schema::create('gtm_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('name', 191);
                $t->string('kind', 12)->default('abm');                  // abm | outbound | nurture | event
                $t->string('status', 12)->default('draft');              // draft | active | paused | completed | archived
                $t->text('goal')->nullable();
                $t->string('industry_pack_slug', 100)->nullable();       // gtm_playbooks.slug of an industry_pack
                $t->unsignedBigInteger('segment_id')->nullable();
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['sub_institute_id', 'status'], 'gtm_campaigns_tenant_status_idx');
            });
        }

        if (! $this->has('gtm_campaign_members')) {
            Schema::create('gtm_campaign_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('campaign_id');
                $t->unsignedBigInteger('account_id');
                $t->unsignedBigInteger('contact_id')->default(0);        // 0 = the account as a whole
                $t->string('status', 10)->default('targeted');           // targeted | excluded
                $t->unsignedBigInteger('added_by')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->unique(['campaign_id', 'account_id', 'contact_id'], 'gtm_campaign_members_unique');
                $t->index(['sub_institute_id', 'campaign_id'], 'gtm_campaign_members_tenant_idx');
                $t->index(['sub_institute_id', 'account_id'], 'gtm_campaign_members_account_idx');
            });
        }

        if ($this->has('gtm_outreach_messages') && ! $this->hasColumn('gtm_outreach_messages', 'campaign_id')) {
            Schema::table('gtm_outreach_messages', function (Blueprint $t) {
                $t->unsignedBigInteger('campaign_id')->nullable()->index('gtm_outreach_campaign_idx');
            });
        }

        $this->addMenus([['Campaigns', '/gtm/campaigns']]);
    }

    public function down(): void
    {
        $this->removeMenus(['/gtm/campaigns']);
        if ($this->has('gtm_outreach_messages') && $this->hasColumn('gtm_outreach_messages', 'campaign_id')) {
            Schema::table('gtm_outreach_messages', function (Blueprint $t) {
                $t->dropIndex('gtm_outreach_campaign_idx');
                $t->dropColumn('campaign_id');
            });
        }
        Schema::dropIfExists('gtm_campaign_members');
        Schema::dropIfExists('gtm_campaigns');
        Schema::dropIfExists('gtm_segments');
    }
};
