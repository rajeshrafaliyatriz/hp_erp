<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_metadata', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->index();
            $table->string('category', 100)->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();

            $table->foreign('template_id', 'template_metadata_template_fk')
                ->references('id')
                ->on('templates')
                ->onDelete('cascade');

            $table->foreign('sub_institute_id', 'template_metadata_sub_institute_fk')
                ->references('id')
                ->on('school_setup')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_metadata');
    }
};