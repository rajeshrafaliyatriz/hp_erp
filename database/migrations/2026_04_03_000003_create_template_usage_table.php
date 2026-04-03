<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('used_at')->useCurrent();
            $table->unsignedBigInteger('sub_institute_id')->index();

            $table->foreign('template_id', 'template_usage_template_fk')
                ->references('id')
                ->on('templates')
                ->onDelete('cascade');

            $table->foreign('sub_institute_id', 'template_usage_sub_institute_fk')
                ->references('id')
                ->on('school_setup')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_usage');
    }
};