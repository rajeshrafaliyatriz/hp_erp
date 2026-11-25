<?php

namespace Tests\Intelligence\Fixtures;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\RightsChecker;
use App\Domain\Intelligence\Support\DetectionClock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal in-memory SQLite shapes of the HR tables the detectors read (only the columns they use; never a copy of real data), plus small helpers.
 * Use inside a class that extends Tests\Intelligence\IntelligenceTestCase, so the isolation guarantees of that class apply.
 */
trait HrTables
{
    /** The fixed "now" every HR test runs at. */
    protected function clock(): DetectionClock
    {
        return DetectionClock::at('2026-11-21 10:00:00');
    }

    protected function createHrTables(): void
    {
        Schema::create('hrms_attendances', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->date('day');
            $t->dateTime('punchin_time')->nullable();
            $t->dateTime('punchout_time')->nullable();
            $t->boolean('status')->default(true);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('hrms_emp_leaves', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->date('from_date')->nullable();
            $t->date('to_date')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->softDeletes();
        });
        Schema::create('hrms_leave_approval_steps', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('leave_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedTinyInteger('step_order')->default(1);
            $t->string('approver_role', 40)->nullable();
            $t->string('status', 20)->default('waiting');
            $t->timestamp('pending_since')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('s_competency_certifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status', 30)->default('valid');
            $t->date('expiry_date')->nullable();
            $t->softDeletes();
        });
        $this->createLmsCertificates();
        $this->createRecruitmentTables(true);
        Schema::create('talent_onboarding_journeys', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->unsignedBigInteger('manager_id')->nullable();
            $t->date('joining_date')->nullable();
            $t->date('probation_end')->nullable();
            $t->string('confirmation_status', 20)->default('pending');
            $t->string('status', 20)->default('not-started');
            $t->softDeletes();
        });
        Schema::create('talent_onboarding_tasks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('journey_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->date('due_date')->nullable();
            $t->string('status', 20)->default('pending');
            $t->timestamp('completed_at')->nullable();
            $t->softDeletes();
        });
    }

    protected function createLmsCertificates(): void
    {
        Schema::create('lms_certificates', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('status', 20)->default('active');
            $t->dateTime('expires_at')->nullable();
            $t->unsignedBigInteger('superseded_by')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
    }

    /** `talent_job_applications` has no creation migration in the repository, so the fixture can be built with or without `deleted_at`. */
    protected function createRecruitmentTables(bool $withDeletedAt): void
    {
        Schema::create('talent_job_applications', function (Blueprint $t) use ($withDeletedAt) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('job_id')->nullable();
            $t->string('status')->nullable();
            $t->date('applied_date')->nullable();
            if ($withDeletedAt) {
                $t->softDeletes();
            }
        });
        Schema::create('talent_offers', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('application_id')->nullable();
            $t->string('status', 20)->default('draft');
            $t->dateTime('sent_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
        });
        // Acceptance lives ONLY here (talent_offers.status is an enum with no 'accepted'): decision accepted | declined | pending.
        Schema::dropIfExists('talent_offer_acceptances');
        Schema::create('talent_offer_acceptances', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('offer_id');
            $t->string('decision', 20)->default('pending');
            $t->dateTime('decided_at')->nullable();
            $t->softDeletes();
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function insertRow(string $table, array $attributes): void
    {
        $this->db()->table($table)->insert($attributes);
    }

    /**
     * Insert `$count` active people of a tenant, all reporting to `$manager` in department `$dept`. Ids start at `$firstId`.
     *
     * @return list<int> the ids
     */
    protected function people(int $count, int $tenant = 1, int $firstId = 100, ?int $manager = 10, ?int $dept = 3): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $firstId + $i;
            $this->insertUser($id, $tenant, ['reporting_manager_id' => $manager, 'department_id' => $dept]);
            $ids[] = $id;
        }

        return $ids;
    }

    /** A caller of the given role; `$granted` lists menu links the in-memory rights checker allows. */
    protected function callerAs(string $role, int $tenant = 1, int $user = 10, ?int $dept = 3, array $granted = []): IntelligenceCaller
    {
        $rights = new class($granted) implements RightsChecker {
            public function __construct(private array $granted)
            {
            }

            public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool
            {
                return in_array($accessLink, $this->granted, true);
            }
        };

        return new IntelligenceCaller($tenant, $user, 7, $role, $dept, $rights);
    }

    /** @return list<string> the entity evidence kinds in an insight array */
    protected function entityTypes(array $insightArray): array
    {
        return array_values(array_map(
            static fn ($e) => $e['entity_type'],
            array_filter($insightArray['evidence'], static fn ($e) => $e['kind'] === 'entity'),
        ));
    }

    /** The value of the first metric evidence line whose label starts with `$prefix`. */
    protected function metric(array $insightArray, string $prefix): mixed
    {
        foreach ($insightArray['evidence'] as $line) {
            if ($line['kind'] === 'metric' && str_starts_with($line['label'], $prefix)) {
                return $line['value'];
            }
        }

        return null;
    }
}
