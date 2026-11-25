<?php

namespace Tests\Intelligence\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal in-memory SQLite shapes of the tables only the cross-functional detectors add (capability-gap requests and their subjects, CRM organisations), with
 * the columns and meanings of their creation migrations (2026_10_14_100100, 2026_11_20_090100). The other tables they read come from CentralTables and
 * CommercialTables. Isolated in-memory SQLite only.
 */
trait CrossTables
{
    protected function createCrossTables(): void
    {
        Schema::create('capability_course_requests', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('competency_id');
            $t->string('status', 20)->default('open');   // open | course_created | dismissed | superseded
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('capability_course_request_subjects', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('user_id');
            $t->dateTime('resolved_at')->nullable();
        });
        Schema::create('crm_organizations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->softDeletes();
        });
    }

    /** An open (by default) capability-gap request; returns its id. */
    protected function gapRequest(int $tenant, int $competency, string $status = 'open', array $extra = []): int
    {
        return (int) $this->db()->table('capability_course_requests')->insertGetId(array_merge(['sub_institute_id' => $tenant, 'competency_id' => $competency, 'status' => $status], $extra));
    }

    /** A person who currently has the gap of a request. The subject's tenant defaults to the request's own; pass `$tenant` to build a hostile mismatch. */
    protected function gapSubject(int $request, int $user, int $tenant, array $extra = []): int
    {
        return (int) $this->db()->table('capability_course_request_subjects')->insertGetId(array_merge(['request_id' => $request, 'sub_institute_id' => $tenant, 'user_id' => $user], $extra));
    }

    protected function organisation(int $tenant, array $extra = []): int
    {
        return (int) $this->db()->table('crm_organizations')->insertGetId(array_merge(['sub_institute_id' => $tenant], $extra));
    }
}
