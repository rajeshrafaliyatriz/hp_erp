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
        Schema::table('tbluser', function (Blueprint $table) {
            $table->time('monday_in_date')->nullable()->change();
            $table->time('monday_out_date')->nullable()->change();
            $table->time('tuesday_in_date')->nullable()->change();
            $table->time('tuesday_out_date')->nullable()->change();
            $table->time('wednesday_in_date')->nullable()->change();
            $table->time('wednesday_out_date')->nullable()->change();
            $table->time('thursday_in_date')->nullable()->change();
            $table->time('thursday_out_date')->nullable()->change();
            $table->time('friday_in_date')->nullable()->change();
            $table->time('friday_out_date')->nullable()->change();
            $table->time('saturday_in_date')->nullable()->change();
            $table->time('saturday_out_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbluser', function (Blueprint $table) {
            //
            $table->timestamp('monday_in_date')->nullable()->change();
            $table->timestamp('monday_out_date')->nullable()->change();
            $table->timestamp('tuesday_in_date')->nullable()->change();
            $table->timestamp('tuesday_out_date')->nullable()->change();
            $table->timestamp('wednesday_in_date')->nullable()->change();
            $table->timestamp('wednesday_out_date')->nullable()->change();
            $table->timestamp('thursday_in_date')->nullable()->change();
            $table->timestamp('thursday_out_date')->nullable()->change();
            $table->timestamp('friday_in_date')->nullable()->change();
            $table->timestamp('friday_out_date')->nullable()->change();
            $table->timestamp('saturday_in_date')->nullable()->change();
            $table->timestamp('saturday_out_date')->nullable()->change();
        });
    }
};
