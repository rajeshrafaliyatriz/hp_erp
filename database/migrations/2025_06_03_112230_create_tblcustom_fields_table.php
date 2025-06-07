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
        Schema::create('tblcustom_fields', function (Blueprint $table) {
             $table->bigIncrements('id');

            $table->string('table_name')->nullable();
            $table->string('table_alias')->nullable();
            $table->integer('tab_sort_order')->nullable();
            $table->string('field_name')->nullable();
            $table->string('column_header')->nullable();
            $table->string('field_label')->nullable();
            $table->string('user_type')->nullable();
            $table->integer('status')->default(1);
            $table->integer('sort_order')->nullable();
            $table->string('field_type')->nullable();
            $table->string('field_message')->nullable();
            $table->string('file_size_max')->nullable();
            $table->integer('required')->default(0);
            $table->char('is_deleted', 1)->default('N');
            $table->integer('common_to_all')->default(0);

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();

            $table->timestamps(); // created_at & updated_at
            $table->softDeletes(); // deleted_at

            // Foreign Keys (NO ACTION on delete/update)
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('created_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblcustom_fields');
    }
};
