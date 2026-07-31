<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task_management_dependencies')) Schema::create('task_management_dependencies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('predecessor_task_id')->index();
            $table->unsignedBigInteger('successor_task_id')->index();
            $table->string('dependency_type', 10)->default('FS');
            $table->integer('lag_days')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('syear', 50)->index();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['sub_institute_id', 'syear', 'predecessor_task_id', 'successor_task_id'],
                'tm_dependency_pair_unique'
            );
        });

        if (!Schema::hasTable('task_management_milestones')) Schema::create('task_management_milestones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('workstream_id')->nullable();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->date('target_date');
            $table->string('status', 30)->default('UPCOMING');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('syear', 50)->index();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

        });

        if (!Schema::hasTable('task_management_task_comments')) Schema::create('task_management_task_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('task_id')->index();
            $table->text('content');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->string('syear', 50)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_management_task_comments');
        Schema::dropIfExists('task_management_milestones');
        Schema::dropIfExists('task_management_dependencies');
    }
};
