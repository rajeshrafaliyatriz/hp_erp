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
        Schema::create('announcement', function (Blueprint $table) {
              $table->bigIncrements('id');

            $table->string('syear', 50);

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->string('title')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('attachment')->nullable();

            $table->date('from_date')->nullable()->index();
            $table->date('to_date')->nullable()->index();

            $table->unsignedBigInteger('user_profile_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraints
            $table->foreign('sub_institute_id')
                  ->references('id')
                  ->on('school_setup')
                  ->onUpdate('NO ACTION')
                  ->onDelete('NO ACTION');

            $table->foreign('user_profile_id')
                  ->references('id')
                  ->on('tbluserprofilemaster')
                  ->onUpdate('NO ACTION')
                  ->onDelete('NO ACTION');

            $table->foreign('created_by')
                  ->references('id')
                  ->on('tbluser')
                  ->onUpdate('NO ACTION')
                  ->onDelete('NO ACTION');

            $table->foreign('updated_by')
                  ->references('id')
                  ->on('tbluser')
                  ->onUpdate('NO ACTION')
                  ->onDelete('NO ACTION');

            $table->foreign('deleted_by')
                  ->references('id')
                  ->on('tbluser')
                  ->onUpdate('NO ACTION')
                  ->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement');
    }
};
