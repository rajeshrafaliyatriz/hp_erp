<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FAQ - legacy CRM's `vtiger_faq`. Confirmed fields: faq_no, question
 * (mandatory), answer(mandatory), category(flat picklist - legacy has no
 * folder/hierarchy concept at all, confirmed no parent-category column
 * exists anywhere), status(Draft/Published/Obsolete - legacy's own
 * customer-portal API hard-gates visibility to status='Published' only).
 * Confirmed NO Organization/Contact relationship exists anywhere in the
 * legacy module - not built here either.
 *
 * FAQ comments are deliberately NOT built (named deferral, not a silent
 * drop) - a much weaker use case without a customer portal in this app,
 * and legacy's own two parallel FAQ comment mechanisms were always
 * secondary to the Q&A content itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_faqs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('faq_no', 100)->unique();
            $table->string('question', 500);
            $table->text('answer')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('status', 20)->default('Draft');
            $table->unsignedBigInteger('product_id')->nullable()->index();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('crm_faqs', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('crm_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_faqs');
    }
};
