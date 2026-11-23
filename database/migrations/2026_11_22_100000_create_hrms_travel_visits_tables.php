<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Travel Visit: an employee starts a trip from the mobile app, the phone
 * reports its position about once a minute, and the employee ends the trip.
 *
 *   hrms_travel_visits        one row per trip (who, why, where from/to, km)
 *   hrms_travel_visit_points  the breadcrumb trail, one row per position fix
 *
 * ── DISTANCE IS WORKED OUT ON THE SERVER ────────────────────────────────────
 *
 * `app_distance` is what the phone said it covered; `distance` is recomputed
 * from the stored points when the trip ends, so a phone cannot simply claim
 * a number. Both are kept: HR can see when they disagree.
 *
 * ── POINTS ARE IDEMPOTENT ───────────────────────────────────────────────────
 *
 * The app queues fixes while offline and re-sends them, so (visit_id,
 * recorded_at) is unique and a repeated batch inserts nothing twice.
 *
 * ── NO FOREIGN KEYS ─────────────────────────────────────────────────────────
 *
 * tbluser is the legacy table shared with the Blade app and live is MariaDB
 * 10.1; existence and tenant are checked in the controller, as elsewhere.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_11_22_100000_create_hrms_travel_visits_tables.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_22_100000_create_hrms_travel_visits_tables.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('hrms_travel_visits')) {
            Schema::create('hrms_travel_visits', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('employee_id');

                // Human reference, e.g. TV/20099/1126/00007 (employee / MMYY / running no.).
                $table->string('visit_no', 40);

                $table->string('purpose', 100)->nullable();
                $table->string('vehicle_type', 50)->nullable();
                $table->text('remarks')->nullable();

                // ongoing | completed
                $table->string('status', 20)->default('ongoing');

                $table->dateTime('start_at');
                $table->dateTime('end_at')->nullable();

                $table->decimal('from_lat', 10, 7)->nullable();
                $table->decimal('from_lng', 10, 7)->nullable();
                $table->string('from_address', 500)->nullable();
                $table->decimal('to_lat', 10, 7)->nullable();
                $table->decimal('to_lng', 10, 7)->nullable();
                $table->string('to_address', 500)->nullable();

                // Kilometres. `distance` is the server's figure from the points.
                $table->decimal('distance', 10, 3)->default(0);
                $table->decimal('app_distance', 10, 3)->nullable();
                $table->decimal('amount', 12, 2)->default(0);

                $table->string('selfie_ref', 500)->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['sub_institute_id', 'visit_no'], 'htv_tenant_no_uq');
                $table->index(['sub_institute_id', 'employee_id', 'status'], 'htv_tenant_emp_status_idx');
                $table->index(['sub_institute_id', 'start_at'], 'htv_tenant_start_idx');
            });
        }

        if (!$this->tableExists('hrms_travel_visit_points')) {
            Schema::create('hrms_travel_visit_points', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('sub_institute_id');
                $table->unsignedBigInteger('visit_id');

                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                // Metres, as the phone reports them.
                $table->float('accuracy')->nullable();
                // Metres per second.
                $table->float('speed')->nullable();

                $table->dateTime('recorded_at');
                $table->timestamp('created_at')->nullable();

                $table->unique(['visit_id', 'recorded_at'], 'htvp_visit_time_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_travel_visit_points');
        Schema::dropIfExists('hrms_travel_visits');
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
