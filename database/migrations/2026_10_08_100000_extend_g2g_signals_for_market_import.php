<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signals Engine, demand-side pipeline (Phase 1): a structured market-signal import.
 *
 * ADDITIVE ONLY. Nothing is dropped or renamed, and existing rows keep working:
 *   - g2g_company_opportunities  += evidence grade, expiry, buyer/need tags, 7 scores, pipeline_status
 *   - g2g_companies              += type, state                (the "buyer organisation")
 *   - g2g_opportunity_matches    += readiness snapshot, deliverability, matched need codes
 *   - g2g_ingestion_sources      += intent (foundation | market)
 *   - NEW g2g_company_aliases, g2g_opportunity_events, g2g_signal_scan_log, g2g_signal_import_rejections
 *   - g2g_company_opportunities.research_run_id becomes nullable: an imported signal has no
 *     web-research run behind it.
 *
 * Every step is guarded by a table/column check, so the migration is safe to run on a database
 * where it was partly applied, and on production where migrations have drifted. `review_status`
 * is untouched; `pipeline_status` is a separate column.
 */
return new class extends Migration
{
    /**
     * Schema::hasColumn() selects information_schema.columns.generation_expression, which old
     * MariaDB servers (10.1) lack, so MySQL/MariaDB use SHOW COLUMNS; other drivers (sqlite in
     * tests) use the portable check.
     */
    private function hasColumn(string $table, string $column): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
        }

        return Schema::hasColumn($table, $column);
    }

    private function isNullable(string $table, string $column): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $row = DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column])[0] ?? null;

            return $row !== null && strtoupper((string) $row->Null) === 'YES';
        }

        foreach (Schema::getColumns($table) as $col) {
            if ($col['name'] === $column) {
                return (bool) $col['nullable'];
            }
        }

        return true;
    }

    /** Add the listed columns that are missing, in one ALTER. @param array<string, callable> $columns */
    private function addMissing(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $missing = array_filter($columns, fn ($define, $name) => ! $this->hasColumn($table, $name), ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $define) {
                $define($t);
            }
        });
    }

    /**
     * g2g_company_opportunities was created with two `useCurrent()` timestamps; on MySQL/MariaDB
     * the second one is stored as `NOT NULL DEFAULT '0000-00-00 00:00:00'`, and a strict
     * session (NO_ZERO_DATE) then refuses ANY ALTER of that table with "Invalid default value".
     * So this migration, and only this migration's session, drops those two zero-date modes while
     * it runs and restores the original mode afterwards. Nothing about the existing data changes.
     */
    public function up(): void
    {
        $isMysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $original = null;

        if ($isMysql) {
            $original = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
            $relaxed = implode(',', array_filter(explode(',', $original), fn ($m) => ! in_array($m, ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE'], true)));
            DB::statement('SET SESSION sql_mode = ?', [$relaxed]);
        }

        try {
            $this->apply();
        } finally {
            if ($isMysql) {
                DB::statement('SET SESSION sql_mode = ?', [$original]);
            }
        }
    }

    private function apply(): void
    {
        // ── opportunities ────────────────────────────────────────────────────
        $this->addMissing('g2g_company_opportunities', [
            'pipeline_status' => fn (Blueprint $t) => $t->string('pipeline_status', 20)->default('NEW'),
            'trigger_type' => fn (Blueprint $t) => $t->string('trigger_type', 30)->nullable(),
            'trigger_summary' => fn (Blueprint $t) => $t->text('trigger_summary')->nullable(),
            'reference_no' => fn (Blueprint $t) => $t->string('reference_no', 191)->nullable(),
            'source_url' => fn (Blueprint $t) => $t->string('source_url', 1000)->nullable(),
            'source_title' => fn (Blueprint $t) => $t->string('source_title', 500)->nullable(),
            'observed_at' => fn (Blueprint $t) => $t->dateTime('observed_at')->nullable(),
            'expires_at' => fn (Blueprint $t) => $t->date('expires_at')->nullable(),
            'claim_level' => fn (Blueprint $t) => $t->string('claim_level', 20)->nullable(),
            'fetch_level' => fn (Blueprint $t) => $t->string('fetch_level', 20)->nullable(),
            'evidence_note' => fn (Blueprint $t) => $t->string('evidence_note', 500)->nullable(),
            'business_fit' => fn (Blueprint $t) => $t->string('business_fit', 20)->nullable(),
            'candidate_need_codes' => fn (Blueprint $t) => $t->longText('candidate_need_codes')->nullable(),
            'buyer_segment' => fn (Blueprint $t) => $t->string('buyer_segment', 50)->nullable(),
            'likely_problem' => fn (Blueprint $t) => $t->text('likely_problem')->nullable(),
            'likely_stakeholder' => fn (Blueprint $t) => $t->string('likely_stakeholder', 191)->nullable(),
            'what_we_could_sell' => fn (Blueprint $t) => $t->text('what_we_could_sell')->nullable(),
            'entry_point' => fn (Blueprint $t) => $t->string('entry_point', 30)->nullable(),
            'scale' => fn (Blueprint $t) => $t->string('scale', 20)->nullable(),
            'estimated_value' => fn (Blueprint $t) => $t->string('estimated_value', 191)->nullable(),
            'is_government_track' => fn (Blueprint $t) => $t->boolean('is_government_track')->default(false),
            'partner_route_note' => fn (Blueprint $t) => $t->text('partner_route_note')->nullable(),
            'soft_marketing_angle' => fn (Blueprint $t) => $t->text('soft_marketing_angle')->nullable(),
            'score_buying_signal' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_buying_signal')->nullable(),
            'score_problem_fit' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_problem_fit')->nullable(),
            'score_product_fit' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_product_fit')->nullable(),
            'score_accessibility' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_accessibility')->nullable(),
            'score_urgency' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_urgency')->nullable(),
            'score_potential_value' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_potential_value')->nullable(),
            'score_evidence_quality' => fn (Blueprint $t) => $t->unsignedTinyInteger('score_evidence_quality')->nullable(),
            'score_total' => fn (Blueprint $t) => $t->unsignedSmallInteger('score_total')->nullable(),
            'score_reasoning' => fn (Blueprint $t) => $t->text('score_reasoning')->nullable(),
            'import_source' => fn (Blueprint $t) => $t->string('import_source', 100)->nullable(),
            'is_sample' => fn (Blueprint $t) => $t->boolean('is_sample')->default(false),
        ]);

        if (Schema::hasTable('g2g_company_opportunities')) {
            if (! $this->isNullable('g2g_company_opportunities', 'research_run_id')) {
                Schema::table('g2g_company_opportunities', function (Blueprint $t) {
                    $t->unsignedBigInteger('research_run_id')->nullable()->change();
                });
            }

            Schema::table('g2g_company_opportunities', function (Blueprint $t) {
                // Named indexes are created only if the columns exist; the guard on
                // pipeline_status/expires_at above makes that true on every driver.
                if (! $this->indexExists('g2g_company_opportunities', 'g2g_opps_tenant_expiry')) {
                    $t->index(['sub_institute_id', 'expires_at'], 'g2g_opps_tenant_expiry');
                }
                if (! $this->indexExists('g2g_company_opportunities', 'g2g_opps_tenant_pipeline')) {
                    $t->index(['sub_institute_id', 'pipeline_status'], 'g2g_opps_tenant_pipeline');
                }
            });
        }

        // ── buyer organisations (g2g_companies) ──────────────────────────────
        $this->addMissing('g2g_companies', [
            'type' => fn (Blueprint $t) => $t->string('type', 30)->nullable(),
            'state' => fn (Blueprint $t) => $t->string('state', 100)->nullable(),
        ]);

        if (! Schema::hasTable('g2g_company_aliases')) {
            Schema::create('g2g_company_aliases', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('company_id');
                $t->string('alias', 191);
                $t->string('normalized_alias', 191);
                $t->timestamps();

                $t->unique(['sub_institute_id', 'normalized_alias'], 'g2g_company_alias_unique');
                $t->index('company_id', 'g2g_company_alias_company');
            });
        }

        // ── matches: readiness snapshot + deliverability ─────────────────────
        $this->addMissing('g2g_opportunity_matches', [
            'matched_need_codes' => fn (Blueprint $t) => $t->longText('matched_need_codes')->nullable(),
            'match_score' => fn (Blueprint $t) => $t->unsignedSmallInteger('match_score')->nullable(),
            'readiness_at_match' => fn (Blueprint $t) => $t->string('readiness_at_match', 100)->nullable(),
            'is_deliverable' => fn (Blueprint $t) => $t->boolean('is_deliverable')->default(false),
            'confidence' => fn (Blueprint $t) => $t->decimal('confidence', 5, 2)->nullable(),
            'rationale' => fn (Blueprint $t) => $t->text('rationale')->nullable(),
        ]);

        // ── ingestion intent ─────────────────────────────────────────────────
        $this->addMissing('g2g_ingestion_sources', [
            'intent' => fn (Blueprint $t) => $t->string('intent', 20)->default('foundation'),
        ]);

        // ── history, scan log, rejection log ─────────────────────────────────
        if (! Schema::hasTable('g2g_opportunity_events')) {
            Schema::create('g2g_opportunity_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('opportunity_id');
                $t->string('event_type', 30);            // created | updated | evidence_downgraded | expired | status
                $t->longText('changes')->nullable();     // {field: [old, new]}
                $t->unsignedBigInteger('scan_log_id')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'opportunity_id'], 'g2g_opp_events_opp');
            });
        }

        if (! Schema::hasTable('g2g_signal_scan_log')) {
            Schema::create('g2g_signal_scan_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->timestamp('ran_at')->nullable();
                $t->string('source_label', 100)->nullable();   // e.g. "daily-agent"
                $t->string('origin', 20)->default('api');      // api | command | ui
                $t->unsignedInteger('records_received')->default(0);
                $t->unsignedInteger('records_accepted')->default(0);
                $t->unsignedInteger('records_rejected')->default(0);
                $t->unsignedInteger('records_duplicate')->default(0);
                $t->unsignedInteger('records_updated')->default(0);
                $t->longText('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'ran_at'], 'g2g_scan_log_tenant_ran');
            });
        }

        if (! Schema::hasTable('g2g_signal_import_rejections')) {
            Schema::create('g2g_signal_import_rejections', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('scan_log_id');
                $t->unsignedInteger('row_index');
                $t->string('reason_code', 40);
                $t->string('reason', 500);
                $t->longText('payload')->nullable();   // the rejected record, so it can be fixed and re-sent
                $t->timestamps();

                $t->index(['sub_institute_id', 'scan_log_id'], 'g2g_import_rej_scan');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if ($existing['name'] === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rolls back only what this migration created. The additive columns are left in place on
     * purpose: dropping them would destroy imported data, and leaving them is harmless to the
     * older code. Drop them by hand only if you are certain no import has run.
     */
    public function down(): void
    {
        Schema::dropIfExists('g2g_signal_import_rejections');
        Schema::dropIfExists('g2g_signal_scan_log');
        Schema::dropIfExists('g2g_opportunity_events');
        Schema::dropIfExists('g2g_company_aliases');
    }
};
