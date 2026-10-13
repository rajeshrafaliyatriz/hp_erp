<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('contact_no', 100)->unique();
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            $table->string('salutation', 20)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('title', 100)->nullable();
            $table->string('department', 100)->nullable();

            $table->string('email', 100)->nullable();
            $table->string('secondary_email', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('fax', 30)->nullable();
            $table->string('home_phone', 30)->nullable();
            $table->string('assistant', 100)->nullable();
            $table->string('assistant_phone', 30)->nullable();

            $table->unsignedBigInteger('reports_to_id')->nullable()->index();
            $table->date('birthday')->nullable();
            $table->string('lead_source', 100)->nullable();
            $table->boolean('do_not_call')->default(false);
            $table->boolean('email_opt_out')->default(false);

            $table->string('mailing_street', 250)->nullable();
            $table->string('mailing_city', 50)->nullable();
            $table->string('mailing_state', 50)->nullable();
            $table->string('mailing_code', 30)->nullable();
            $table->string('mailing_country', 50)->nullable();
            $table->string('mailing_po_box', 30)->nullable();

            $table->string('other_street', 250)->nullable();
            $table->string('other_city', 50)->nullable();
            $table->string('other_state', 50)->nullable();
            $table->string('other_code', 30)->nullable();
            $table->string('other_country', 50)->nullable();
            $table->string('other_po_box', 30)->nullable();

            $table->text('description')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('crm_organizations')->nullOnDelete();
            $table->foreign('reports_to_id')->references('id')->on('crm_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
