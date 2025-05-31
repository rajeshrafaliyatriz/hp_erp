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
        Schema::create('tblclient', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('client_name');
            $table->string('short_code')->nullable();
            $table->string('logo')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_mobile')->nullable();
            $table->string('contact_persoon_email')->nullable();
            $table->string('trustee_name')->nullable()->nullable();
            $table->string('trustee_emai')->nullable()->nullable();
            $table->string('trustee_mobile')->nullable()->nullable();
            $table->integer('number_of_schools')->nullable();
            $table->string('db_host')->nullable();
            $table->string('db_user')->nullable();
            $table->string('db_password')->nullable();
            $table->string('db_solution')->nullable();
            $table->string('db_cms')->nullable();
            $table->string('db_hrms')->nullable();
            $table->string('db_library')->nullable();
            $table->string('db_lms')->nullable();
            $table->boolean('multischool')->nullable();
            $table->integer('total_student')->nullable();
            $table->integer('total_staff')->nullable();
            $table->string('hrms_folder')->nullable();
            $table->string('old_url')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblclient');
    }
};
