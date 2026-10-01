<?php

namespace Tests\Feature;

use App\Domain\Signals\Signal;
use App\Domain\Signals\SignalRun;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs on the in-memory sqlite database configured in phpunit.xml. The legacy
 * tables the engine reads are created here in their minimal shape; the Signals
 * tables come from the real migration, so the migration itself is exercised.
 */
class SignalsEngineTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->string('first_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('hrms_departments', function ($t) {
            $t->id();
            $t->string('department');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('head_user_id')->nullable();
            $t->integer('status')->default(1);
            $t->unsignedBigInteger('sub_institute_id');
            $t->integer('sort_order')->default(0);
            $t->softDeletes();
        });

        (require base_path('database/migrations/2026_09_30_120000_create_g2g_signals_tables.php'))->up();

        RoleKey::flushCache();
        Cache::flush();
        config(['ai.provider.gemini.api_key' => 'test-key', 'signals.ai_attempts' => 1]);

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function tokenFor(int $tenant, int $profile = 1): string
    {
        $id = DB::table('tbluser')->insertGetId([
            'user_name' => "u{$tenant}-{$profile}-" . uniqid(),
            'sub_institute_id' => $tenant,
            'user_profile_id' => $profile,
        ]);

        return tbluserModel::find($id)->createToken('test')->plainTextToken;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function department(int $tenant, string $name = 'Engineering'): int
    {
        return DB::table('hrms_departments')->insertGetId(['department' => $name, 'sub_institute_id' => $tenant]);
    }

    private function signal(int $tenant, array $overrides = []): Signal
    {
        return Signal::create($overrides + [
            'sub_institute_id' => $tenant,
            'title' => 'Signal ' . uniqid(),
            'summary' => 'Summary',
            'explanation' => 'Explanation',
            'signal_type' => 'risk',
            'why_it_matters' => 'Because',
            'recommended_action' => 'Do something',
            'priority' => 'Medium',
            'evidence' => [],
            'sources' => [],
            'origin' => 'internal',
            'fingerprint' => hash('sha256', uniqid('', true)),
            'status' => 'New',
            'generated_at' => now(),
        ]);
    }

    private function fakeGemini(array|string $payload, int $status = 200): void
    {
        $text = is_string($payload) ? $payload : json_encode($payload);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ], $status)]);
    }

    private function groundedSignal(int $departmentId, array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Engineering has no department head',
            'summary' => 'The department has no head assigned.',
            'explanation' => 'No head is set while employees are assigned.',
            'signal_type' => 'structure',
            'why_it_matters' => 'Approvals have no owner.',
            'recommended_action' => 'Nominate a department head.',
            'priority' => 'High',
            'department_id' => $departmentId,
            'evidence' => [['department_id' => $departmentId, 'metric' => 'has_department_head', 'value' => 0]],
        ];
    }

    // ── retrieval, filters, pagination ───────────────────────────────────────

    public function test_requires_a_token(): void
    {
        $this->getJson('/api/signals')->assertStatus(401);
    }

    public function test_lists_only_own_tenant_and_shows_summary(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->signal(self::T1, ['priority' => 'High']);
        $this->signal(self::T1, ['status' => 'Reviewed']);
        $this->signal(self::T2, ['priority' => 'High']);

        $response = $this->getJson('/api/signals', $this->auth($token))->assertOk();

        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.new', 1)
            ->assertJsonPath('summary.high_priority', 1)
            ->assertJsonPath('summary.reviewed', 1);
    }

    public function test_cannot_read_or_modify_another_tenants_signal(): void
    {
        $token = $this->tokenFor(self::T1);
        $foreign = $this->signal(self::T2);

        $this->getJson("/api/signals/{$foreign->id}", $this->auth($token))->assertStatus(404);
        $this->patchJson("/api/signals/{$foreign->id}/review", [], $this->auth($token))->assertStatus(404);
        $this->patchJson("/api/signals/{$foreign->id}/dismiss", [], $this->auth($token))->assertStatus(404);

        $this->assertSame('New', $foreign->fresh()->status);
    }

    public function test_tenant_query_parameter_cannot_override_token_tenant(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->signal(self::T2);

        $this->getJson('/api/signals?sub_institute_id=' . self::T2, $this->auth($token))
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_filters_combine_and_paginate(): void
    {
        $token = $this->tokenFor(self::T1);
        $dept = $this->department(self::T1);
        foreach (range(1, 3) as $i) {
            $this->signal(self::T1, ['department_id' => $dept, 'priority' => 'High', 'signal_type' => 'risk', 'title' => "Risk {$i}"]);
        }
        $this->signal(self::T1, ['department_id' => $dept, 'priority' => 'Low', 'signal_type' => 'risk', 'title' => 'Minor']);
        $this->signal(self::T1, ['priority' => 'High', 'signal_type' => 'opportunity', 'title' => 'Chance']);
        $this->signal(self::T1, ['priority' => 'High', 'status' => 'Dismissed', 'signal_type' => 'opportunity', 'department_id' => $dept, 'title' => 'Old news']);

        $q = fn (string $qs) => $this->getJson("/api/signals?{$qs}", $this->auth($token))->assertOk();

        $q("department_id={$dept}&priority=High&type=risk")->assertJsonPath('meta.total', 3);
        $q('status=Dismissed')->assertJsonPath('meta.total', 1);
        $q('search=Chance')->assertJsonPath('meta.total', 1);
        $q("department_id={$dept}&per_page=2&page=2")->assertJsonPath('meta.last_page', 3)->assertJsonCount(2, 'data');
        $q('from=' . now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
        $this->getJson('/api/signals?priority=Urgent', $this->auth($token))->assertStatus(422);
        $this->getJson('/api/signals?per_page=500', $this->auth($token))->assertStatus(422);
    }

    public function test_high_priority_sorts_first_and_detail_includes_evidence(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->signal(self::T1, ['priority' => 'Low', 'title' => 'low one']);
        $high = $this->signal(self::T1, ['priority' => 'High', 'title' => 'high one', 'evidence' => [['metric' => 'employee_count', 'value' => 3]]]);

        $this->getJson('/api/signals', $this->auth($token))->assertJsonPath('data.0.title', 'high one');
        $this->getJson("/api/signals/{$high->id}", $this->auth($token))
            ->assertOk()->assertJsonPath('data.evidence.0.metric', 'employee_count');
    }

    // ── review / dismiss / authorization ─────────────────────────────────────

    public function test_admin_can_review_and_dismiss(): void
    {
        $token = $this->tokenFor(self::T1);
        $signal = $this->signal(self::T1);

        $this->patchJson("/api/signals/{$signal->id}/review", [], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'Reviewed');
        $this->patchJson("/api/signals/{$signal->id}/dismiss", [], $this->auth($token))
            ->assertOk()->assertJsonPath('data.status', 'Dismissed');
        $this->assertNotNull($signal->fresh()->reviewed_by);
    }

    public function test_employee_cannot_review_or_generate(): void
    {
        $token = $this->tokenFor(self::T1, 2);
        $signal = $this->signal(self::T1);
        Http::fake();

        $this->patchJson("/api/signals/{$signal->id}/review", [], $this->auth($token))->assertStatus(403);
        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(403);
        $this->getJson('/api/signals', $this->auth($token))->assertOk();
        Http::assertNothingSent();
    }

    // ── generation ───────────────────────────────────────────────────────────

    public function test_manual_generation_stores_grounded_signals_and_records_the_run(): void
    {
        $token = $this->tokenFor(self::T1);
        $dept = $this->department(self::T1);
        $this->fakeGemini(['signals' => [$this->groundedSignal($dept)]]);

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);

        $signal = Signal::where('sub_institute_id', self::T1)->sole();
        $this->assertSame('High', $signal->priority);
        $this->assertSame('internal', $signal->origin);
        $this->assertSame($dept, $signal->department_id);

        $run = SignalRun::sole();
        $this->assertSame('success', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(1, $run->signals_generated);
        $this->assertNotNull($run->duration_ms);

        $this->getJson('/api/signals/status', $this->auth($token))
            ->assertOk()->assertJsonPath('data.latest_run.status', 'success')->assertJsonPath('data.running', false);
    }

    public function test_ungrounded_and_invented_signals_are_rejected(): void
    {
        $token = $this->tokenFor(self::T1);
        $dept = $this->department(self::T1);
        $this->fakeGemini(['signals' => [
            $this->groundedSignal($dept),
            $this->groundedSignal($dept, ['title' => 'Invented statistic', 'evidence' => [['department_id' => $dept, 'metric' => 'employee_count', 'value' => 999]]]),
            $this->groundedSignal($dept, ['title' => 'No evidence', 'evidence' => []]),
            $this->groundedSignal(99999, ['title' => 'Unknown department', 'evidence' => [['department_id' => 99999, 'metric' => 'employee_count', 'value' => 1]]]),
            $this->groundedSignal($dept, ['title' => 'Bad priority', 'priority' => 'Urgent']),
        ]]);

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);

        $this->assertSame(1, Signal::count());
        $run = SignalRun::sole();
        $this->assertSame('partial', $run->status);
        $this->assertSame(4, $run->signals_rejected);
    }

    public function test_repeated_run_does_not_duplicate_and_keeps_history(): void
    {
        $token = $this->tokenFor(self::T1);
        $dept = $this->department(self::T1);
        $this->fakeGemini(['signals' => [$this->groundedSignal($dept)]]);

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);
        // The scheduled path, as a second day's run would take it.
        $this->artisan('signals:generate', ['--tenant' => self::T1])->assertExitCode(0);

        $this->assertSame(1, Signal::count());
        $second = SignalRun::orderByDesc('id')->first();
        $this->assertSame('scheduled', $second->trigger);
        $this->assertSame(1, $second->signals_duplicate);
        $this->assertSame(0, $second->signals_generated);
        $this->assertSame(2, SignalRun::count());
    }

    public function test_manual_generation_has_a_cooldown(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        $this->fakeGemini(['signals' => []]);

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);
        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(429)
            ->assertJsonPath('code', 'cooldown');
    }

    public function test_invalid_ai_json_marks_the_run_failed_without_leaking_details(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        $this->fakeGemini('this is not json at all');

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);

        $run = SignalRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('generation_failed', $run->error_code);
        $this->assertStringNotContainsString('json', strtolower($run->error_message));
        $this->assertSame(0, Signal::count());
    }

    public function test_provider_error_marks_the_run_failed_and_stores_nothing(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'secret provider detail'], 500)]);

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);

        $run = SignalRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringNotContainsString('secret', (string) $run->error_message);
        $this->assertSame(0, Signal::count());
    }

    public function test_rejected_provider_key_gets_an_actionable_message(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);

        $run = SignalRun::sole();
        $this->assertSame('ai_invalid_credentials', $run->error_code);
        $this->assertStringContainsString('AI Providers', $run->error_message);
    }

    public static function providerFailures(): array
    {
        return [
            'invalid key' => [400, ['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 'ai_invalid_credentials'],
            'forbidden' => [403, ['error' => ['message' => 'nope']], 'ai_invalid_credentials'],
            'billing' => [402, ['error' => ['message' => 'Insufficient Balance']], 'ai_billing'],
            'rate limit' => [429, ['error' => ['message' => 'slow down']], 'ai_rate_limited'],
            'bad model' => [404, ['error' => ['message' => 'models/x is not found']], 'ai_model_error'],
            'outage' => [503, ['error' => ['message' => 'overloaded']], 'ai_provider_unavailable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerFailures')]
    public function test_provider_failures_are_classified(int $status, array $body, string $expectedCode): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);
        \Illuminate\Support\Facades\Log::spy();

        $this->postJson('/api/signals/generate', [], $this->auth($token))->assertStatus(202);

        $run = SignalRun::sole();
        $this->assertSame($expectedCode, $run->error_code);
        // The provider's own wording and the key never reach the stored message.
        $this->assertStringNotContainsString('test-key', (string) $run->error_message);
        $this->assertStringNotContainsString($body['error']['message'], (string) $run->error_message);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(
            fn ($msg, $ctx) => $msg === 'signals.run_failed'
                && ($ctx['code'] ?? null) === $expectedCode
                && ($ctx['http_status'] ?? null) === $status
                && ($ctx['provider'] ?? null) === 'gemini'
                && ! str_contains(json_encode($ctx), 'test-key')
        )->once();
    }

    public function test_placeholder_key_counts_as_not_configured(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        config(['ai.provider.gemini.api_key' => 'YOUR_GEMINI_API_KEY']);
        Http::fake();

        $this->postJson('/api/signals/generate', [], $this->auth($token))
            ->assertStatus(422)->assertJsonPath('code', 'ai_not_configured');
        $this->getJson('/api/signals/status', $this->auth($token))
            ->assertJsonPath('data.ai_configured', false);
        Http::assertNothingSent();
    }

    public function test_generation_is_refused_when_no_ai_key_is_configured(): void
    {
        $token = $this->tokenFor(self::T1);
        $this->department(self::T1);
        config(['ai.provider.gemini.api_key' => null]);
        Http::fake();

        $this->postJson('/api/signals/generate', [], $this->auth($token))
            ->assertStatus(422)->assertJsonPath('code', 'ai_not_configured');
        Http::assertNothingSent();
    }

    public function test_organisation_without_departments_is_skipped_not_fabricated(): void
    {
        Http::fake();

        $this->artisan('signals:generate', ['--tenant' => self::T1])->assertExitCode(0);

        $run = SignalRun::sole();
        $this->assertSame('skipped', $run->status);
        $this->assertSame('insufficient_data', $run->error_code);
        $this->assertSame(0, Signal::count());
        Http::assertNothingSent();
    }

    public function test_prompt_contains_no_personal_data_and_no_other_tenant(): void
    {
        $dept = $this->department(self::T1, 'Engineering');
        $this->department(self::T2, 'OtherTenantDept');
        DB::table('tbluser')->insert(['user_name' => 'jane.secret', 'first_name' => 'Jane', 'sub_institute_id' => self::T1, 'department_id' => $dept]);
        $this->fakeGemini(['signals' => []]);

        $this->artisan('signals:generate', ['--tenant' => self::T1])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains($body, 'Engineering')
                && ! str_contains($body, 'jane.secret')
                && ! str_contains($body, 'Jane')
                && ! str_contains($body, 'OtherTenantDept');
        });
    }

    public function test_scheduler_registers_the_daily_ist_task(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'signals:generate'));

        $this->assertNotNull($event, 'signals:generate is not scheduled');
        $this->assertSame('Asia/Kolkata', (string) $event->timezone);
        $this->assertSame('0 10 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
