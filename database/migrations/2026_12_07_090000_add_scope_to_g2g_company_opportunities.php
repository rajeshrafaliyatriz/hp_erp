<?php

use App\Support\ColumnExists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The scope an opportunity was found for, stored ON the opportunity: product, sub-product (offer), customer model, the ICP profile and its
 * version, and the selected region. Until now this lived only on the research run, and an opportunity was matched to a selection through a
 * hash of the run's whole configuration, so editing an ICP or an offer made every older opportunity vanish from its own selection.
 *
 * Additive and nullable. A NULL means "found before scope was recorded" and is NEVER guessed: the backfill below copies a run's scope onto the
 * opportunities that run produced, only when the run itself recorded a customer model (a run made before scope was recorded has none, and its
 * opportunities stay unassigned). Nothing is deleted or rewritten. The ICP version is not backfilled: it cannot be established reliably.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_research_runs') && ! ColumnExists::has('g2g_research_runs', 'icp_version')) {
            Schema::table('g2g_research_runs', function (Blueprint $t) {
                $t->unsignedInteger('icp_version')->nullable();
            });
        }
        if (! Schema::hasTable('g2g_company_opportunities')) {
            return;
        }
        $added = false;
        foreach (['product_key' => fn (Blueprint $t) => $t->string('product_key', 40)->nullable(),
            'offer_id' => fn (Blueprint $t) => $t->string('offer_id', 80)->nullable(),
            'customer_model' => fn (Blueprint $t) => $t->string('customer_model', 8)->nullable(),
            'icp_profile_id' => fn (Blueprint $t) => $t->unsignedBigInteger('icp_profile_id')->nullable(),
            'icp_version' => fn (Blueprint $t) => $t->unsignedInteger('icp_version')->nullable(),
            'region_scope' => fn (Blueprint $t) => $t->text('region_scope')->nullable()] as $column => $define) {
            if (! ColumnExists::has('g2g_company_opportunities', $column)) {
                Schema::table('g2g_company_opportunities', fn (Blueprint $t) => $define($t));
                $added = true;
            }
        }
        if ($added) {
            Schema::table('g2g_company_opportunities', function (Blueprint $t) {
                $t->index(['sub_institute_id', 'product_key', 'customer_model'], 'g2g_opp_scope');
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        if (! Schema::hasTable('g2g_research_runs') || ! ColumnExists::has('g2g_research_runs', 'customer_model')) {
            return;
        }
        $hasProduct = ColumnExists::has('g2g_research_runs', 'product_key');
        $hasProfile = ColumnExists::has('g2g_research_runs', 'icp_profile_id');
        DB::table('g2g_research_runs')->whereNotNull('customer_model')->orderBy('id')->chunkById(200, function ($runs) use ($hasProduct, $hasProfile) {
            foreach ($runs as $run) {
                DB::table('g2g_company_opportunities')
                    ->where('sub_institute_id', $run->sub_institute_id)->where('research_run_id', $run->id)->whereNull('customer_model')
                    ->update([
                        'product_key' => $hasProduct ? $run->product_key : null,
                        'offer_id' => $run->offer_id ?? null,
                        'customer_model' => $run->customer_model,
                        'icp_profile_id' => $hasProfile ? $run->icp_profile_id : null,
                        'region_scope' => $run->region_scope ?? null,
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Left in place on purpose: dropping would discard the scope of every opportunity found since. The columns are nullable and harmless.
    }
};
