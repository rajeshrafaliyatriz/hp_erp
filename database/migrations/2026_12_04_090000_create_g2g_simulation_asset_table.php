<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simulation Learning: the registry of assets (the binary glTF models and the PNG, JPEG or WebP images a simulation's scene shows).
 *
 * The file itself lives in the project's DigitalOcean Spaces bucket (disk `digitalocean`, PRIVATE, at `sim_assets/{organisation id or
 * "platform"}/{sha256}.{glb|png|jpg|webp}`); this table is what says it exists, whose it is, and under which licence and credit it may be shown. A
 * specification refers to an asset by its sha256 (`assets.models[].sha256`), never by address, and the browser never reads the bucket: the
 * file is streamed through GET /api/lms/simulations/{id}/assets/{sha256}, which checks the caller's organisation first.
 *
 *   g2g_simulation_asset   one row per (organisation, file). `sub_institute_id` is the owning organisation; NULL means a PLATFORM asset that
 *                          any organisation's simulation may use. `licence` and `credit` are optional here but the artisan command that adds an
 *                          asset (simulations:asset-add) refuses to add one without them, so the credits shown to learners are never empty.
 *
 * The unique index covers (organisation, sha256); MariaDB allows several NULLs in it, so the command checks for a platform duplicate itself.
 * No foreign keys, no JSON column, short index names - the same rules as the tables migration (live is MariaDB 10.1.48).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_04_090000_create_g2g_simulation_asset_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_04_090000_create_g2g_simulation_asset_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->exists('g2g_simulation_asset')) {
            Schema::create('g2g_simulation_asset', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->nullable();   // owning organisation; NULL = a platform asset
                $table->char('sha256', 64);                                    // of the file's bytes; how a specification names it
                $table->string('name', 120);
                $table->string('kind', 16)->default('model');                  // model | image
                $table->string('mime', 80)->default('model/gltf-binary');      // image/png, image/jpeg or image/webp for an image
                $table->unsignedInteger('bytes');
                $table->string('storage_path', 255);                           // on the disk; never sent to a browser
                $table->string('licence', 80)->nullable();
                $table->string('credit', 300)->nullable();
                $table->string('source_url', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['sub_institute_id', 'sha256'], 'g2gsimast_tenant_sha');
                $table->index('sha256', 'g2gsimast_sha');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_simulation_asset');
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
