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
        Schema::create('custom_module_table_columns', function (Blueprint $table) {
            $table->id();
            $table->string('column_name');
            $table->string('type');
            $table->string('length');
            $table->boolean('not_null');
            $table->boolean('auto_increment');
            $table->string('index')->nullable();
            $table->string('default')->nullable();
            // $table->foreign('table_id')->references('id')->on('custom_module_tables')->onDelete('cascade');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->foreign('table_id')->references('id')->on('custom_module_tables')->onDelete('cascade');
            $table->string('field_type');
            $table->json('field_value');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_module_table_columns');
    }
};
