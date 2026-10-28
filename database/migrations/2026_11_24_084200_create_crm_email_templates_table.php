<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Templates - legacy CRM's `vtiger_emailtemplates` (a reusable,
 * folder-organized subject+body library for later mail-merge use -
 * confirmed from the real schema.xml, no send/recipient data on this
 * table itself). `folder` is a flat string label, not a real folder
 * tree - legacy's own folder concept was similarly flat admin grouping,
 * not a hierarchy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_email_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('name', 191);
            $table->string('folder', 100)->nullable();
            $table->string('subject', 255);
            $table->text('body');

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_templates');
    }
};
