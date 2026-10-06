<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trainers who deliver sessions and courses.
 *
 * New model: nothing in the schema represented a trainer. Sessions carry
 * trainer_name and trainer_email as free text on lms_virtual_classroom, which
 * is why the same person is spelled differently across sessions and cannot be
 * reported on. This table gives them an identity; user_id links the ones who
 * are also employees, and stays null for external contractors.
 *
 * Columns follow the existing lms_virtual_classroom naming so the two can be
 * reconciled later without a rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_trainers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id')->index();
            /** tbluser.id when the trainer is an employee; null for externals. */
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            /** 'internal' or 'external' - drives whether a vendor applies. */
            $table->string('trainer_type', 20)->default('internal');
            /** lms_vendors.id for contracted trainers. */
            $table->unsignedBigInteger('vendor_id')->nullable()->index();

            $table->string('specialisation', 191)->nullable();
            $table->text('bio')->nullable();
            /** Comma-separated, matching how sub_std_map stores jobrole. */
            $table->text('qualifications')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->string('currency', 10)->nullable();

            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_trainers');
    }
};
