<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `product_id` is a single nullable reference, not a many-to-many relation -
 * confirmed in research that the legacy CRM has no Products/Services
 * many-to-many for Campaigns, just this one optional column. Don't
 * over-build a junction table that doesn't correspond to real behavior.
 *
 * The ROI/expected-vs-actual fields are plain manually-entered numbers with
 * zero rollup logic anywhere in the legacy system - stored as-is here too;
 * auto-computation from linked deals would be new functionality, not a port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('campaign_no', 100)->unique();
            $table->string('name', 191);
            $table->string('campaign_type', 100)->nullable();
            $table->string('campaign_status', 100)->nullable()->index();

            $table->decimal('expected_revenue', 15, 2)->nullable();
            $table->decimal('budget_cost', 15, 2)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->string('expected_response', 50)->nullable();
            $table->unsignedInteger('num_sent')->nullable();

            $table->string('sponsor', 191)->nullable();
            $table->string('target_audience', 191)->nullable();
            $table->unsignedInteger('target_size')->nullable();
            $table->unsignedInteger('expected_response_count')->nullable();
            $table->unsignedInteger('expected_sales_count')->nullable();
            $table->unsignedInteger('actual_response_count')->nullable();
            $table->unsignedInteger('actual_sales_count')->nullable();
            $table->decimal('expected_roi', 15, 2)->nullable();
            $table->decimal('actual_roi', 15, 2)->nullable();
            $table->date('closing_date')->nullable();

            $table->unsignedBigInteger('product_id')->nullable();

            $table->text('description')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaigns');
    }
};
