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
        Schema::create('tblmenumaster', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('menu_name');
            $table->unsignedBigInteger('parent_id')->default(0)->nullable()->index();
            $table->integer('level')->default(1)->nullable();
            $table->string('page_type')->nullable();
            $table->string('access_link')->nullable()->index();
            $table->string('icon')->nullable();
            $table->boolean('status')->default(1)->index();
            $table->integer('sort_order')->default(1);
            $table->text('sub_institute_id')->nullable();
            $table->string('menu_type')->nullable()->index();
             $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblmenumaster');
    }
};
