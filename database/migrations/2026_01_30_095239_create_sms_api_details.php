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
        Schema::create('sms_api_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            $table->text('url');
            $table->text('pram');
            $table->text('mobile_var');
            $table->text('text_var');
            $table->text('last_var')->nullable();

            $table->integer('sub_institute_id')->default(0);
            $table->integer('is_active')->default(1);

            $table->timestamp('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_api_details');
    }
};
