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
        Schema::create('lms_mapping_type', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->tinyText('name')->nullable();
            $table->bigInteger('parent_id')->index()->nullable();
            $table->integer('globally')->index()->nullable();
            $table->unsignedBigInteger('chapter_id')->nullable();
            $table->unsignedBigInteger('topic_id')->nullable();
            $table->integer('status')->index()->default(1);
            $table->string('type', 25)->index()->nullable();
            $table->string('element_id', 25)->index()->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            // Foreign keys - no action on delete or update
            $table->foreign('created_by')
                  ->references('id')->on('tbluser')
                  ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                  ->references('id')->on('tbluser')
                  ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                  ->references('id')->on('tbluser')
                  ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_mapping_type');
    }
};
