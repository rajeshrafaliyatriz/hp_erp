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
        Schema::create('task_automation_classification', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->text('clean_title')->nullable();
            $table->decimal('rule_score', 10, 2)->nullable();
            $table->string('rule_classification')->nullable();
            $table->string('llm_classification')->nullable();
            $table->decimal('automation_confidence_score', 10, 2)->nullable();
            $table->decimal('final_score', 10, 2)->nullable();
            $table->string('final_classification')->nullable();
            $table->string('decision_source')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->unsignedBigInteger('sub_institude_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes();
            
            $table->foreign('task_id')->references('id')->on('task')->onDelete('cascade');
            $table->foreign('sub_institude_id')->references('id')->on('school_setup')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_automation_classification');
    }
};
