<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opportunity <-> Contact, many-to-many - mirrors legacy's
 * `vtiger_contpotentialrel` (contactid, potentialid). A legacy Opportunity's
 * "Contact" is a relation, not a column, same here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunity_contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunity_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->timestamp('created_at')->nullable();

            $table->unique(['opportunity_id', 'contact_id'], 'crm_opp_contacts_pair_unique');
            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_contacts');
    }
};
