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
        Schema::create('tblgroupwise_rights', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->foreign('menu_id')->references('id')->on('tblmenumaster')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->foreign('profile_id')->references('id')->on('tbluserprofilemaster')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->integer('can_view')->default(0)->nullable()->index();
            $table->integer('can_add')->default(0)->nullable()->index();
            $table->integer('can_edit')->default(0)->nullable()->index();
            $table->integer('can_delete')->default(0)->nullable()->index();
            $table->integer('dashboard_right')->nullable()->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                    ->references('id')
                    ->on('school_setup')
                    ->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');  
            $table->integer('sort_order')->nullable()->index();
             $table->softDeletes();
            $table->timestamps();

            $table->index('menu_id');
            $table->index('profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblgroupwise_rights');
    }
};
