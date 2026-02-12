<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('talent_offer_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sub_institute_id');
            $table->string('module_name', 255)->nullable();
            $table->string('title', 255)->nullable();
            $table->longText('html_content');
            $table->integer('sort_order')->nullable();
            $table->integer('status')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['sub_institute_id'], 'result_template_master_sub_institute_id_index');
            $table->index(['created_by'], 'result_template_master_created_by_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_offer_templates');
    }
};
