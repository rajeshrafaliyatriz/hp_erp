<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GTM forecasting.
 *
 *   php artisan migrate --path=database/migrations/2026_10_11_100000_gtm_forecasting.php
 *
 * gtm_stage_settings holds the probability and forecast category an ADMINISTRATOR chose for each
 * open stage. There are no default rows and no default probabilities: a weighted forecast built on
 * numbers nobody chose would be a fabricated figure, so until a tenant configures them the
 * forecast reports "not configured" and shows only unweighted pipeline.
 *
 * gtm_forecast_snapshots is written by the daily `gtm:forecast-snapshot` command. It is what makes
 * "forecast accuracy" computable later: a snapshot taken before a period began can be compared
 * with what was actually won in it. Until snapshots exist the screen says so.
 *
 * gtm_deals.forecast_category is an optional per-deal override (pipeline|best_case|commit|omitted).
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if (! $this->has('gtm_stage_settings')) {
            Schema::create('gtm_stage_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('stage', 20);
                $t->decimal('probability', 5, 2)->nullable();            // 0-100, chosen by an administrator
                $t->string('category', 12)->default('pipeline');          // pipeline | best_case | commit | omitted
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();

                $t->unique(['sub_institute_id', 'stage'], 'gtm_stage_settings_unique');
            });
        }

        if (! $this->has('gtm_forecast_snapshots')) {
            Schema::create('gtm_forecast_snapshots', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->date('snapshot_date');
                $t->char('period', 7);                                    // YYYY-MM of expected close
                $t->char('currency', 3);
                $t->unsignedInteger('deal_count')->default(0);
                $t->decimal('pipeline_value', 16, 2)->default(0);
                $t->decimal('best_case_value', 16, 2)->default(0);
                $t->decimal('commit_value', 16, 2)->default(0);
                $t->decimal('weighted_value', 16, 2)->nullable();        // null when probabilities were not configured
                $t->timestamp('created_at')->nullable();

                $t->unique(['sub_institute_id', 'snapshot_date', 'period', 'currency'], 'gtm_forecast_snapshots_unique');
            });
        }

        if ($this->has('gtm_deals') && ! $this->hasColumn('gtm_deals', 'forecast_category')) {
            Schema::table('gtm_deals', function (Blueprint $t) {
                $t->string('forecast_category', 12)->nullable();
            });
        }

        $this->addMenus([['Forecast', '/gtm/forecast']]);
    }

    public function down(): void
    {
        $this->removeMenus(['/gtm/forecast']);
        if ($this->has('gtm_deals') && $this->hasColumn('gtm_deals', 'forecast_category')) {
            Schema::table('gtm_deals', fn (Blueprint $t) => $t->dropColumn('forecast_category'));
        }
        Schema::dropIfExists('gtm_forecast_snapshots');
        Schema::dropIfExists('gtm_stage_settings');
    }
};
