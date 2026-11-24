<?php

namespace Tests\Intelligence\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal in-memory SQLite shapes of the platform / AI governance / agentic tables, plus row helpers. The shapes follow the real migrations and include the
 * columns the detectors must NEVER read (credential blob, test message, run output, error text, payloads, prompts) so a test can prove they are not selected.
 * No data is copied from anywhere; these tables exist only inside the isolated test process (see IntelligenceTestCase).
 */
trait PlatformTables
{
    /** A value that must never appear in any Intelligence response or SQL. */
    public const SECRET_SENTINEL = 'SECRET-SENTINEL-do-not-leak';

    protected function createPlatformTables(): void
    {
        Schema::create('g2g_platform_task_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('task_key', 191);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->string('status', 16)->nullable();
            $t->integer('exit_code')->nullable();
            $t->text('output_head')->nullable();
            $t->string('host', 191)->nullable();
        });
        Schema::create('g2g_platform_scheduled_tasks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('task_key', 191);
            $t->boolean('disabled')->default(false);
            $t->string('updated_by', 191)->nullable();
        });
        Schema::create('g2g_integration_credentials', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('provider_key', 64);
            $t->string('status', 20)->default('not_configured');
            $t->longText('config')->nullable();
            $t->timestamp('last_tested_at')->nullable();
            $t->string('last_test_message', 500)->nullable();
            $t->string('created_by', 191)->nullable();
            $t->string('updated_by', 191)->nullable();
        });
        Schema::create('g2g_event', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('type', 96)->default('x.y');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('payload')->nullable();
            $t->dateTime('recorded_at');
        });
        Schema::create('g2g_event_delivery', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('event_id');
            $t->string('consumer', 96);
            $t->string('status', 16)->default('pending');
            $t->integer('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->dateTime('completed_at')->nullable();
        });
        Schema::create('ai_usage_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('ai_module', 64);
            $t->string('provider', 40)->default('p');
            $t->string('model', 120)->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->decimal('estimated_cost_usd', 12, 6)->nullable();
            $t->decimal('estimated_cost', 14, 6)->nullable();
            $t->char('cost_currency', 3)->nullable();
            $t->string('outcome', 16)->default('success');
            $t->text('error')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('ai_usage_quotas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('ai_module', 64)->nullable();
            $t->string('period', 16)->default('month');
            $t->unsignedBigInteger('token_limit');
            $t->unsignedTinyInteger('warn_at_percent')->default(80);
            $t->boolean('status')->default(true);
        });
        Schema::create('ai_policies', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('name', 191)->default('Policy');
            $t->tinyInteger('status')->default(1);
            $t->tinyInteger('require_acknowledgement')->default(0);
        });
        Schema::create('ai_policy_assignments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('policy_id');
            $t->string('scope_type', 40)->default('global');
            $t->unsignedBigInteger('scope_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->tinyInteger('status')->default(1);
        });
        Schema::create('ai_policy_acknowledgements', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('policy_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->tinyInteger('acknowledged')->default(0);
        });
        Schema::create('agentic_agents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('name', 191)->default('Agent');
            $t->longText('system_prompt')->nullable();
            $t->string('status', 20)->default('draft');
            $t->softDeletes();
        });
        Schema::create('agentic_agent_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('agent_id');
            $t->string('status', 20)->default('pending');
            $t->longText('input')->nullable();
            $t->longText('output')->nullable();
            $t->text('error_message')->nullable();
            $t->decimal('cost', 12, 6)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->softDeletes();
        });
    }

    /** @param array<string, mixed> $o */
    protected function addTaskRun(string $key, ?int $tenant, string $status = 'ok', string $startedAt = '2026-11-21 08:00:00', array $o = []): int
    {
        return (int) $this->db()->table('g2g_platform_task_runs')->insertGetId(array_merge([
            'task_key' => $key, 'sub_institute_id' => $tenant, 'started_at' => $startedAt,
            'finished_at' => $status === 'open' ? null : $startedAt, 'status' => $status === 'open' ? null : $status,
            'output_head' => self::SECRET_SENTINEL, 'host' => self::SECRET_SENTINEL,
        ], $o));
    }

    protected function addIntegration(int $tenant, string $provider, string $status, ?string $testedAt = '2026-11-15 00:00:00'): void
    {
        $this->db()->table('g2g_integration_credentials')->insert([
            'sub_institute_id' => $tenant, 'provider_key' => $provider, 'status' => $status, 'last_tested_at' => $testedAt,
            'config' => self::SECRET_SENTINEL, 'last_test_message' => self::SECRET_SENTINEL, 'created_by' => self::SECRET_SENTINEL, 'updated_by' => self::SECRET_SENTINEL,
        ]);
    }

    /** @return int the event id */
    protected function addEvent(int $tenant, string $recordedAt = '2026-11-20 08:00:00'): int
    {
        return (int) $this->db()->table('g2g_event')->insertGetId(['sub_institute_id' => $tenant, 'recorded_at' => $recordedAt, 'payload' => self::SECRET_SENTINEL]);
    }

    protected function addDelivery(int $eventId, string $status = 'done', string $consumer = 'audit_projector'): void
    {
        $this->db()->table('g2g_event_delivery')->insert(['event_id' => $eventId, 'consumer' => $consumer, 'status' => $status, 'last_error' => self::SECRET_SENTINEL]);
    }

    /** One event with one delivery in one go. */
    protected function addEventWithDelivery(int $tenant, string $status = 'done', string $recordedAt = '2026-11-20 08:00:00', string $consumer = 'audit_projector'): int
    {
        $id = $this->addEvent($tenant, $recordedAt);
        $this->addDelivery($id, $status, $consumer);

        return $id;
    }

    /** @param array<string, mixed> $o */
    protected function addAiCall(?int $tenant, string $module = 'lms.course_builder', string $outcome = 'success', string $at = '2026-11-15 08:00:00', array $o = []): void
    {
        $this->db()->table('ai_usage_events')->insert(array_merge([
            'ai_module' => $module, 'outcome' => $outcome, 'sub_institute_id' => $tenant, 'created_at' => $at, 'input_tokens' => 100, 'output_tokens' => 50,
            'error' => self::SECRET_SENTINEL, 'model' => self::SECRET_SENTINEL, 'user_id' => 7,
        ], $o));
    }

    protected function addQuota(int $tenant, ?string $module, string $period, int $limit, int $warn = 80, int $status = 1): void
    {
        $this->db()->table('ai_usage_quotas')->insert(['sub_institute_id' => $tenant, 'ai_module' => $module, 'period' => $period, 'token_limit' => $limit, 'warn_at_percent' => $warn, 'status' => $status]);
    }

    /** @return int the policy id */
    protected function addPolicy(?int $tenant, int $status = 1, int $ack = 0): int
    {
        return (int) $this->db()->table('ai_policies')->insertGetId(['sub_institute_id' => $tenant, 'status' => $status, 'require_acknowledgement' => $ack]);
    }

    protected function assignPolicy(int $policyId, ?int $tenant, int $status = 1): void
    {
        $this->db()->table('ai_policy_assignments')->insert(['policy_id' => $policyId, 'sub_institute_id' => $tenant, 'status' => $status]);
    }

    /** @return int the agent id */
    protected function addAgent(?int $tenant, string $status = 'deployed', ?string $deletedAt = null): int
    {
        return (int) $this->db()->table('agentic_agents')->insertGetId(['sub_institute_id' => $tenant, 'status' => $status, 'deleted_at' => $deletedAt, 'system_prompt' => self::SECRET_SENTINEL]);
    }

    /** @param array<string, mixed> $o */
    protected function addAgentRun(int $tenant, int $agentId, string $status = 'success', string $createdAt = '2026-11-15 08:00:00', ?float $cost = null, array $o = []): void
    {
        $this->db()->table('agentic_agent_runs')->insert(array_merge([
            'sub_institute_id' => $tenant, 'agent_id' => $agentId, 'status' => $status, 'created_at' => $createdAt, 'cost' => $cost,
            'input' => self::SECRET_SENTINEL, 'output' => self::SECRET_SENTINEL, 'error_message' => self::SECRET_SENTINEL,
        ], $o));
    }
}
