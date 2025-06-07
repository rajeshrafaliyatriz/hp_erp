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
        Schema::create('tblfields_data', function (Blueprint $table) {
             $table->bigIncrements('id');

            $table->unsignedBigInteger('field_id')->index();
            $table->string('display_text')->nullable();
            $table->string('display_value')->nullable();
            $table->timestamp('created_on')->useCurrent();

            // Foreign Key Constraint (Assuming field_id references tblcustom_fields)
            $table->foreign('field_id')
                ->references('id')->on('tblcustom_fields')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblfields_data');
    }
};
