<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales & Marketing gap-closure, Wave 5: list-based/campaign email sending.
 * `campaign_id` is a plain nullable tag, no FK - same style as this table's
 * existing `template_id` and as crm_opportunities' own `campaign_id`, never
 * populated by CrmEmailController::compose()'s one-off single-recipient
 * send, only by the new CrmCampaignController::send() batch path.
 *
 * Its whole purpose is idempotency: before (re-)sending a campaign, the
 * batch send looks up which (campaign_id, linked_type, linked_id) pairs
 * already have a Sent row here and skips them - so clicking "Send" a second
 * time (a resumed batch after the daily cap was hit, a retry, a double
 * click) can never email the same recipient the same campaign twice. Without
 * this column there would be no way to ask "has this campaign already
 * emailed this person," short of fuzzy-matching on subject text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->index()->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_emails', function (Blueprint $table) {
            $table->dropColumn('campaign_id');
        });
    }
};
