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
            $table->string('portal_user',5)->nullable()->change();
            $table->string('last_login',20)->nullable()->change();
            $table->char('tds_deduction')->nullable()->change();
            $table->char('pf_deduction')->nullable()->change();
            $table->char('pt_deduction')->nullable()->change();
            $table->time('sunday_in_date')->nullable()->change();
            $table->time('sunday_out_date')->nullable()->change();
            $table->string('total_experience',10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbluser', function (Blueprint $table) {
            $table->tinyInt('portal_user',1)->nullable()->change();
            $table->timestamp('last_login')->nullable()->change();
            $table->tinyInt('tds_deduction',1)->nullable()->change();
            $table->tinyInt('pf_deduction',1)->nullable()->change();
            $table->tinyInt('pt_deduction',1)->nullable()->change();
            $table->timestamp('sunday_in_date')->nullable()->change();
            $table->timestamp('sunday_out_date')->nullable()->change();
            $table->integer('total_experience')->nullable()->change();;
        });
    }
};
