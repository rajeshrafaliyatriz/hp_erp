<?php

namespace Tests\Intelligence;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Base class for every Intelligence test.
 *
 * Isolation guarantees (see tests/Intelligence/bootstrap.php for the environment half):
 *   1. NO `.env` FILE IS READ. The application is told to load a file that does not exist, so configuration comes only from the forced test environment.
 *   2. The default connection MUST be in-memory SQLite; if it is anything else the test aborts before a single query runs.
 *   3. Tables are created per test from the fixtures below: minimal shapes of the real tables (only the columns the detectors read), never a copy of data.
 */
abstract class IntelligenceTestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.intelligence-tests-do-not-create');
        $app->make(Kernel::class)->bootstrap();

        $default = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.sqlite.database');
        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException('Intelligence tests refuse to run: the default connection is not in-memory SQLite.');
        }

        return $app;
    }

    /** The in-memory connection (the only one these tests may use). */
    protected function db(): \Illuminate\Database\ConnectionInterface
    {
        return $this->app['db']->connection();
    }

    /** Create `tbluser` (the columns the people scope and active-employee rule read) and `school_setup`. */
    protected function createPeopleTables(): void
    {
        Schema::create('school_setup', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('SchoolName')->nullable();
            $t->softDeletes();
        });
        Schema::create('tbluser', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('reporting_manager_id')->nullable();
            $t->unsignedBigInteger('jobtitle_id')->nullable();
            $t->boolean('status')->nullable()->default(true);
            $t->date('terminated_date')->nullable();
            $t->date('joined_date')->nullable();
            $t->boolean('is_admin')->nullable();
            $t->timestamp('monday_in_date')->nullable();
            $t->softDeletes();
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function insertUser(int $id, ?int $tenant, array $attributes = []): void
    {
        $this->db()->table('tbluser')->insert(array_merge([
            'id' => $id,
            'sub_institute_id' => $tenant,
            'status' => 1,
            'terminated_date' => null,
            'deleted_at' => null,
        ], $attributes));
    }
}
