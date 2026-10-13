<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets - legacy CRM's `vtiger_troubletickets` ("HelpDesk" internally,
 * "Tickets" in the UI - confirmed via languages/en_us/HelpDesk.php).
 * Confirmed fields: ticket_no, title(mandatory), status(mandatory:
 * Open/Wait For Response/Closed), priority(Normal/High/Urgent),
 * severity(Minor/Major/Feature/Critical), category(Big Problem/Small
 * Problem/Other Problem), description, solution, hours+days(decimal -
 * time-spent, feeds the Service Contract consumption math below, NOT an
 * SLA deadline - confirmed no SLA/due-date field exists anywhere in
 * legacy, not built here either).
 *
 * `asset_id`/`service_contract_id` replace legacy's generic polymorphic
 * `vtiger_crmentityrel` M:M relation with plain nullable FKs - matches how
 * every other relationship in this CRM subsystem is modeled (organization_
 * id/contact_id/opportunity_id are all plain FKs, never a generic relate
 * table anywhere in this app's CRM) and is the only shape that makes the
 * consumption math in CrmTicketController unambiguous (one ticket's hours/
 * days count against exactly one contract's bucket). `product_id` and
 * `asset_id` are intentionally both present even though an asset implies a
 * product via its own product_id - inherited from legacy's own shape, not
 * redundancy to clean up.
 *
 * Deliberately NOT replicated: legacy's `update_log` denormalized text
 * blob (history as "Status Changed to X.--//--..." string concatenation) -
 * replaced by the real `crm_ticket_status_history` table instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tickets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('ticket_no', 100)->unique();
            $table->string('title', 191);
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->unsignedBigInteger('service_contract_id')->nullable()->index();

            $table->string('status', 30)->default('Open');
            $table->string('priority', 20)->nullable();
            $table->string('severity', 20)->nullable();
            $table->string('category', 30)->nullable();

            $table->text('description')->nullable();
            $table->text('solution')->nullable();
            $table->decimal('hours', 10, 2)->nullable();
            $table->decimal('days', 10, 2)->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_tickets', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
            $table->foreign('asset_id')->references('id')->on('crm_assets')->nullOnDelete();
            $table->foreign('service_contract_id')->references('id')->on('crm_service_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tickets');
    }
};
