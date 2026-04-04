<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->longText('content');
            $table->integer('version')->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('name', 'idx_name');
            $table->index('status', 'idx_status');
            $table->index('created_by', 'idx_created_by');

            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
