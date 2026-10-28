<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `crm_leads` turned out to exist ONLY on the default/local connection (the
 * abandoned 2026-07-01 prototype was never run against `live`) - confirmed
 * by the previous migration's own guarded no-op there. This migration fills
 * that gap: creates the table fresh, wherever it is missing, with the exact
 * final shape the previous migration produced by altering the local copy
 * (column-for-column, matching `SHOW COLUMNS`/`SHOW INDEX` taken from local
 * after that migration ran) - so both databases end up identical regardless
 * of which path (ALTER vs CREATE) got them there.
 *
 * No-ops wherever the table already exists (local).
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_leads')) {
            return;
        }

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('lead_no', 30)->nullable()->unique('crm_leads_lead_no_unique');
            $table->string('salutation', 20)->nullable();
            $table->string('first_name', 191)->nullable();
            $table->string('last_name', 191);
            $table->string('company', 191)->nullable()->index();
            $table->string('email', 191)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable()->index();
            $table->string('fax', 30)->nullable();
            $table->string('lead_source', 191)->nullable()->index();
            $table->string('industry', 191)->nullable();
            $table->string('website', 191)->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->string('lead_status', 191)->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->index();
            $table->string('number_of_emp', 50)->nullable();
            $table->string('rating', 191)->nullable();
            $table->string('secondary_email', 191)->nullable();
            $table->boolean('email_opt_out')->default(false);
            $table->string('type', 191)->nullable();
            $table->string('campaign_text', 191)->nullable();
            $table->boolean('converted')->default(false)->index();
            $table->unsignedBigInteger('converted_organization_id')->nullable()->index();
            $table->unsignedBigInteger('converted_contact_id')->nullable()->index();
            $table->string('street', 191)->nullable();
            $table->string('po_box', 191)->nullable();
            $table->string('postal_code', 191)->nullable();
            $table->string('country', 191)->nullable();
            $table->string('state', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->tinyText('description')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('converted_organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('converted_contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Only drop if the previous migration's own create-branch never had
        // a pre-existing table to alter here in the first place - i.e. never
        // drop on a connection where crm_leads predates this migration pair.
        // Since that predates this migration pair, rolling back is left as a
        // no-op; a genuine drop should be a deliberate, separate decision.
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
