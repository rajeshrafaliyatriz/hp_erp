<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_course_outlines stored the generated outline but nothing about the
 * presentation built from it, so a deck could never be reopened after the
 * dialog closed. These columns record which model produced the outline and
 * where the rendered deck lives.
 *
 * Every column is nullable, so the 59 existing rows stay valid and the current
 * /api/save-generated-course consumer is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_course_outlines', function (Blueprint $table) {
            $table->string('presentation_platform', 50)->nullable()->after('outline');
            $table->string('ai_model', 100)->nullable()->after('presentation_platform');
            $table->unsignedInteger('slide_count')->nullable()->after('ai_model');
            $table->string('generation_id', 191)->nullable()->index()->after('slide_count');
            $table->text('gamma_url')->nullable()->after('generation_id');
            $table->text('export_url')->nullable()->after('gamma_url');
            // pending/completed/failed mirror Gamma's own statuses; draft is a
            // saved outline with no deck rendered yet.
            $table->string('status', 20)->nullable()->default('draft')->after('export_url');
            // Set once the outline has been turned into a catalogue course.
            $table->unsignedBigInteger('course_id')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_course_outlines', function (Blueprint $table) {
            $table->dropColumn([
                'presentation_platform',
                'ai_model',
                'slide_count',
                'generation_id',
                'gamma_url',
                'export_url',
                'status',
                'course_id',
            ]);
        });
    }
};
