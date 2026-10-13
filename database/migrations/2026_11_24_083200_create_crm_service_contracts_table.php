<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Contracts - legacy CRM's `vtiger_servicecontracts` (a support-
 * hours/incidents consumption bucket tracked against a date range, not a
 * line-item/invoice-style contract). Confirmed fields: contract_no,
 * subject, start_date/end_date/due_date, tracking_unit (Hours/Days/
 * Incidents), total_units/used_units/progress, contract_status,
 * contract_type, priority. `sc_related_to` was a single polymorphic int
 * column resolving at runtime to either an Account or a Contact - replaced
 * here with two plain nullable FKs (organization_id/contact_id), matching
 * how every other CRM entity in this app models an optional dual Org/
 * Contact reference (e.g. crm_quotes) rather than a generic polymorphic
 * column, which nothing else in this subsystem uses.
 *
 * `used_units`/`progress` are server-maintained only - written exclusively
 * by CrmTicketController's consumption-math (on a linked ticket's
 * close/reopen/edit) and CrmServiceContractController's own auto-complete
 * helper, never accepted from the client. `total_units` is nullable -
 * null means uncapped, and such a contract can never self-complete from
 * consumption alone (only a manual status edit can close it).
 *
 * Confirmed NO relationship to Assets exists anywhere in legacy (grepped
 * both directions) - not built here either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_service_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('contract_no', 100)->unique();
            $table->string('subject', 191);
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('due_date')->nullable();

            $table->string('tracking_unit', 20)->nullable();
            $table->decimal('total_units', 10, 2)->nullable();
            $table->decimal('used_units', 10, 2)->default(0);
            $table->decimal('progress', 5, 2)->default(0);

            $table->string('contract_status', 30)->default('In Planning');
            $table->string('contract_type', 30)->nullable();
            $table->string('priority', 20)->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_service_contracts', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_service_contracts');
    }
};
