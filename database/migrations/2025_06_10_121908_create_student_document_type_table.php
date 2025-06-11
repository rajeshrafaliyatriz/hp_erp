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
        Schema::create('student_document_type', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('document_type',191)->index();
            $table->string('user_type',191)->index()->nullable();
            $table->string('status',10)->index()->nullable();

            // Laravel's default timestamp columns: created_at and updated_at
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_document_type');
    }
};
