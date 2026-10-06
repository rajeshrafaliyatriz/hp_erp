<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training vendors - the organisations content and trainers are bought from.
 *
 * New model: no vendor concept existed anywhere in the schema. The governance
 * page counts and lists them, and lms_trainers.vendor_id points here, so an
 * external trainer can be traced to the contract they came under.
 *
 * contract_start / contract_end are what make the "expiring contracts" view
 * possible; without dates a vendor list is only a directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->string('name', 191);
            $table->string('vendor_code', 100)->nullable();
            $table->string('contact_person', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website', 191)->nullable();
            $table->text('address')->nullable();

            /** What they supply: 'content', 'trainers', 'platform', 'mixed'. */
            $table->string('service_type', 50)->nullable();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->decimal('contract_value', 14, 2)->nullable();
            $table->string('currency', 10)->nullable();

            $table->boolean('status')->default(true);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_vendors');
    }
};
