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
        Schema::create('school_setup', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('SchoolName');
            $table->string('ShortCode')->nullable()->index();
            $table->string('ContactPerson')->nullable();
            $table->string('Mobile')->nullable()->index();
            $table->string('Email')->nullable()->index();
            $table->string('ReceiptHeader')->nullable();
            $table->string('ReceiptAddress')->nullable();
            $table->string('FeeEmail')->nullable();
            $table->string('ReceiptContact')->nullable();
            $table->integer('SortOrder')->default(0)->nullable()->index();
            $table->string('Logo')->nullable();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->foreign('client_id')->references('id')->on('tblclient');
            $table->char('is_lms', 1)->default('N')->nullable()->index();
            $table->integer('cheque_return_charges')->nullable();
            $table->string('syear')->nullable()->index();
            $table->date('expire_date')->nullable()->index();
            $table->integer('given_space_mb')->nullable();
            $table->string('institute_type')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_setup');
    }
};
