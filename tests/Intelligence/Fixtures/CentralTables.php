<?php

namespace Tests\Intelligence\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal in-memory SQLite shapes of the tables the four "central" detectors read (organisation structure, task workflow, learning assignments, capability
 * development): only the columns they use, never a copy of real data. Column names and types follow the creation migrations (see each detector's docblock).
 * Use inside a class that extends Tests\Intelligence\IntelligenceTestCase and uses HrTables (clock, people(), callerAs(), insertRow(), metric()).
 */
trait CentralTables
{
    protected function createCentralTables(): void
    {
        Schema::create('hrms_departments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('department')->nullable();
            $t->integer('status')->default(1);
            $t->unsignedBigInteger('head_user_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('task', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('status', 50)->nullable();
            $t->date('task_date')->nullable();
            $t->unsignedBigInteger('task_allocated')->nullable();
            $t->unsignedBigInteger('task_allocated_to')->nullable();
            $t->string('SYEAR', 50)->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        $this->createTaskHistory();
        Schema::create('lms_assignments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('course_id');
            $t->string('assignment_type')->default('Mandatory');
            $t->date('due_date')->nullable();
            $t->string('status')->default('Not Started');
            $t->string('approval_status')->default('approved');
            $t->unsignedBigInteger('competency_id')->nullable();   // 2026_07_29_100300: the competency link read by the cross-functional detector
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('lms_course_enroll', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('course_id');
            $t->string('status', 20)->default('enrolled');
            $t->timestamp('updated_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('lms_content_progress', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('course_id');
            $t->timestamp('updated_at')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        foreach (['s_competency_development_plans' => 'active', 's_competency_assessments' => 'open'] as $table => $defaultStatus) {
            Schema::create($table, function (Blueprint $t) use ($defaultStatus) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('status', 30)->default($defaultStatus);
                $t->date('due_date')->nullable();
                $t->dateTime('completed_at')->nullable();
                $t->softDeletes();
            });
        }
    }

    protected function createTaskHistory(): void
    {
        Schema::create('task_status_history', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('task_id');
            $t->boolean('is_reopen')->default(false);
            $t->dateTime('occurred_at');
        });
    }

    /** @param array<string, mixed> $extra */
    protected function task(?int $assignee, ?string $due, ?string $status = 'PENDING', int $tenant = 1, array $extra = []): int
    {
        return (int) $this->db()->table('task')->insertGetId(array_merge(
            ['sub_institute_id' => $tenant, 'task_allocated_to' => $assignee, 'task_date' => $due, 'status' => $status], $extra,
        ));
    }

    /** @param array<string, mixed> $extra */
    protected function assignment(int $user, ?string $due, string $status = 'Not Started', int $tenant = 1, array $extra = []): int
    {
        return (int) $this->db()->table('lms_assignments')->insertGetId(array_merge(
            ['user_id' => $user, 'course_id' => 500 + $user, 'due_date' => $due, 'status' => $status, 'sub_institute_id' => $tenant], $extra,
        ));
    }

    /** @param array<string, mixed> $extra */
    protected function record(string $table, ?int $user, ?string $due, ?string $status = null, int $tenant = 1, array $extra = []): int
    {
        $row = ['sub_institute_id' => $tenant, 'user_id' => $user, 'due_date' => $due] + ($status === null ? [] : ['status' => $status]);

        return (int) $this->db()->table($table)->insertGetId(array_merge($row, $extra));
    }

    /** @param list<\App\Domain\Intelligence\Insight> $insights  @return array<string, array> category => insight array */
    protected function byCategory(array $insights): array
    {
        $out = [];
        foreach ($insights as $i) {
            $out[$i->category] = $i->toArray();
        }

        return $out;
    }

    /** The whole result serialised, for "no SQL or table text leaked" assertions. */
    protected function serialised(\App\Domain\Intelligence\DetectorResult $r): string
    {
        return json_encode([$r->summary(), array_map(static fn ($i) => $i->toArray(), $r->insights)]);
    }

    protected function assertNoSqlLeak(string $json): void
    {
        foreach (['select ', 'SELECT', 'from `', 'no such', 'SQLSTATE', 'sqlite', 'WHERE ', 'Illuminate'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }
}
