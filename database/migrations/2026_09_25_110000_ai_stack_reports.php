<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports for the shared AI Stack's Templates tab: creates `ai_generated_reports`, the saved documents a "Build report"
 * produces (LMS_K12's schema; `arguments` is longText because this estate's MariaDB 10.1 has no JSON type).
 *
 * It used to also seed developer-written "(example)" report layouts and chat suggestions. That was retired: an AI Stack
 * tab shows what was saved or measured, never a worked example. Rows it created earlier are removed by
 * `php artisan ai:remove-seeded-examples`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('ai_generated_reports')) {
            Schema::create('ai_generated_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sub_institute_id')->index();
                $table->unsignedBigInteger('client_id')->nullable();
                $table->string('module_key', 60)->index();
                $table->unsignedBigInteger('layout_template_id')->nullable()->index();
                $table->string('title', 250);
                $table->longText('html_content');
                $table->text('question')->nullable();
                $table->string('source_tool', 120)->nullable();
                $table->longText('arguments')->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['sub_institute_id', 'created_at'], 'ai_gr_institute_created');
            });
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generated_reports');
    }

    /**
     * Schema::hasTable() throws on this estate's live MariaDB — its query asks
     * information_schema.columns for a `generation_expression` field that database does
     * not have. This crashed the migration on first deploy before the tables it guards
     * were ever created, so it is answered directly against information_schema instead,
     * the same fix already used throughout this migration's siblings.
     */
    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
