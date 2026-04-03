<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('template_id'); 
            $table->longText('content');
            $table->integer('version');
            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('template_id')->references('id')->on('templates')->onDelete('cascade');
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->nullOnDelete();
            $table->index(['template_id', 'version'], 'idx_template_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_versions');
    }
};