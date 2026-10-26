<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simulation Learning: the simulations themselves, stored as data.
 *
 * A simulation is a typed specification (schema "g2g.sim/1": the knobs, pools, caches, traffic scenarios, missions, hints and scene of one
 * system the learner operates), played by ONE generic runtime (g2gv0/public/sim-runtime). Nothing about a particular simulation lives in code;
 * which simulations exist, for which organisation, in which version, is rows in these tables.
 *
 *   g2g_simulation           one row per simulation: the card (title, summary, category, minutes, cover) and its state. `sub_institute_id` is
 *                            the owning organisation; NULL means a PLATFORM simulation that any organisation may switch on.
 *   g2g_simulation_version   every saved version of the spec, immutable, with its sha256. A published simulation plays its `current_version`.
 *   g2g_simulation_tenant    which organisations have switched a platform simulation on (and off again).
 *   g2g_simulation_progress  where each learner is in each simulation: one row per (simulation, user).
 *
 * `spec` is LONGTEXT, not json(): the `live` connection's MariaDB (10.1.48) predates native JSON (10.2.7+), and a json() column fails
 * outright there. The application encodes and decodes it. No foreign keys (the rest of this schema has none, and live has missed migrations
 * before); every read joins on ids and filters on the organisation.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_090000_create_g2g_simulation_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_090000_create_g2g_simulation_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->exists('g2g_simulation')) {
            Schema::create('g2g_simulation', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->nullable();   // owning organisation; NULL = a platform simulation
                $table->string('slug', 80);                                    // the spec's own id, stable across versions
                $table->string('title', 160);
                $table->string('subtitle', 160)->nullable();
                $table->text('summary')->nullable();
                $table->string('category', 60)->default('general');            // the filter chips of the gallery
                $table->string('tags', 255)->nullable();                       // comma separated
                $table->string('audience', 160)->nullable();
                $table->unsignedSmallInteger('minutes')->nullable();
                $table->string('difficulty', 20)->nullable();                  // intro | intermediate | advanced
                $table->string('cover_url', 500)->nullable();
                $table->string('status', 16)->default('draft');               // draft | published | archived
                $table->unsignedInteger('current_version')->default(0);
                $table->unsignedSmallInteger('missions')->default(0);          // read from the spec when it is saved, so a card needs no spec
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['sub_institute_id', 'status'], 'g2gsim_tenant_status');
                $table->index(['sub_institute_id', 'slug'], 'g2gsim_tenant_slug');
            });
        }

        if (! $this->exists('g2g_simulation_version')) {
            Schema::create('g2g_simulation_version', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('simulation_id');
                $table->unsignedInteger('version');
                $table->longText('spec');                                      // the canonical JSON of the specification
                $table->char('sha256', 64);
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['simulation_id', 'version'], 'g2gsimver_sim_version');
            });
        }

        if (! $this->exists('g2g_simulation_tenant')) {
            Schema::create('g2g_simulation_tenant', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('simulation_id');
                $table->unsignedBigInteger('sub_institute_id');
                $table->tinyInteger('enabled')->default(1);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['simulation_id', 'sub_institute_id'], 'g2gsimten_sim_tenant');
                $table->index('sub_institute_id', 'g2gsimten_tenant');
            });
        }

        if (! $this->exists('g2g_simulation_progress')) {
            Schema::create('g2g_simulation_progress', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('simulation_id');
                $table->unsignedInteger('version')->default(0);
                $table->unsignedBigInteger('user_id');
                $table->string('status', 16)->default('in_progress');          // in_progress | completed
                $table->unsignedSmallInteger('step')->default(0);
                $table->unsignedTinyInteger('percent')->default(0);
                $table->text('result')->nullable();                            // what the runtime reported (stars per mission), as JSON text
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['simulation_id', 'user_id'], 'g2gsimprog_sim_user');
                $table->index(['sub_institute_id', 'user_id'], 'g2gsimprog_tenant_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_simulation_progress');
        Schema::dropIfExists('g2g_simulation_tenant');
        Schema::dropIfExists('g2g_simulation_version');
        Schema::dropIfExists('g2g_simulation');
    }

    /** Schema::hasTable() can throw on live's MariaDB 10.1, so fall back to information_schema directly. */
    private function exists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return DB::select(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$table]
            ) !== [];
        }
    }
};
