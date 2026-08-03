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
        Schema::create('tblgroupwise_rights_g2g', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('menu_id')->index();
            $table->unsignedBigInteger('profile_id')->index();
            $table->boolean('can_view')->default(0);
            $table->boolean('can_add')->default(0);
            $table->boolean('can_edit')->default(0);
            $table->boolean('can_delete')->default(0);
            $table->boolean('dashboard_right')->default(0);
            $table->boolean('is_mobile')->default(0);
            $table->text('sub_institute_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblgroupwise_rights_g2g');
    }
};
