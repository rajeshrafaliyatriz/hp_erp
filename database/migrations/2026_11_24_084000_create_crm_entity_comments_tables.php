<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments for Leads/Contacts/Organizations/Opportunities - exact structural
 * clones of crm_ticket_comments (own FK, not polymorphic; confirmed by
 * exhaustive grep that no generic "commentable" mechanism exists anywhere
 * in this codebase - every comment table hardcodes one parent FK column).
 * Scope matches legacy vtiger's own real `ModComments` wiring list
 * (modules/ModComments/ModComments.php's vtlib_handler(): Leads, Contacts,
 * Accounts, Potentials, Project, ProjectTask - the last two aren't CRM).
 *
 * No soft-deletes - append-only, same reasoning as crm_ticket_comments and
 * every CRM status-history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->text('comment');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('crm_leads')->cascadeOnDelete();
        });

        Schema::create('crm_contact_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->text('comment');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('contact_id')->references('id')->on('crm_contacts')->cascadeOnDelete();
        });

        Schema::create('crm_organization_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->text('comment');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('crm_organizations')->cascadeOnDelete();
        });

        Schema::create('crm_opportunity_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunity_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->text('comment');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_comments');
        Schema::dropIfExists('crm_organization_comments');
        Schema::dropIfExists('crm_contact_comments');
        Schema::dropIfExists('crm_lead_comments');
    }
};
