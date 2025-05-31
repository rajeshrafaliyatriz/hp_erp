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
        Schema::create('tbluserprofilemaster', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('parent_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->integer('sort_order')->nullable();
            $table->integer('status')->nullable()->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                    ->references('id')
                    ->on('school_setup')
                    ->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');  
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->foreign('client_id')->references('id')->on('tblclient')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    
    public function down(): void
    {
        Schema::dropIfExists('tbluserprofilemaster');
    }
};
