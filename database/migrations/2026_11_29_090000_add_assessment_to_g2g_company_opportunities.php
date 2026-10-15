<?php

use App\Support\ColumnExists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ScholarClone signals keep their separate assessments (source reliability, evidence, buying intent, sales pull, product fit,
 * opportunity strength, what remains unverified) so the card can explain itself. Additive and nullable: existing rows and the
 * old import path are untouched. `assessment` is plain longText holding JSON (live MariaDB 10.1 has no JSON type);
 * `buying_intent` is its own short column so the feed can filter on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_company_opportunities')) {
            return;
        }
        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            if (! ColumnExists::has('g2g_company_opportunities', 'assessment')) {
                $t->longText('assessment')->nullable();
            }
            if (! ColumnExists::has('g2g_company_opportunities', 'buying_intent')) {
                $t->string('buying_intent', 20)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('g2g_company_opportunities')) {
            return;
        }
        Schema::table('g2g_company_opportunities', function (Blueprint $t) {
            foreach (['assessment', 'buying_intent'] as $col) {
                if (ColumnExists::has('g2g_company_opportunities', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
