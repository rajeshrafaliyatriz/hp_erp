<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\settings\instituteDetailController;
use App\Models\auth\tbluserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tenant isolation, proven on the in-memory sqlite database with synthetic rows only.
 *
 * Why this exists: docs/organization-audit reported F-134/F-135 (one organisation reading or editing another's
 * compliance records by naming its sub_institute_id). The controller now carries the fix; this pins it, including
 * the WRITE path the audit declined to demonstrate against live data.
 *
 * It also pins ResolvesApiIdentity's contract - the token owner's organisation wins over anything in the request -
 * and records (does not endorse) the one documented exception: an account with no organisation of its own.
 */
class TenantIsolationTest extends TestCase
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
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('master_compliance', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('title')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function user(?int $tenant): int
    {
        return DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant]);
    }

    private function token(int $userId): string
    {
        return tbluserModel::find($userId)->createToken('t')->plainTextToken;
    }

    private function resolver(): object
    {
        return new class {
            use ResolvesApiIdentity;

            public function tenant(Request $r): ?int
            {
                return $this->apiTenantId($r);
            }

            public function identity(Request $r)
            {
                return $this->resolveApiIdentity($r);
            }
        };
    }

    private function request(array $params, ?string $bearer = null): Request
    {
        $r = Request::create('/x', 'POST', $params);
        if ($bearer !== null) {
            $r->headers->set('Authorization', 'Bearer ' . $bearer);
        }

        return $r;
    }

    // ── the resolver's contract ──────────────────────────────────────────────

    public function test_token_owners_tenant_wins_over_a_requested_tenant(): void
    {
        $token = $this->token($this->user(self::T1));

        $this->assertSame(self::T1, $this->resolver()->tenant($this->request(['sub_institute_id' => self::T2], $token)));
    }

    public function test_missing_invalid_and_expired_tokens_resolve_to_no_tenant(): void
    {
        $r = $this->resolver();

        $this->assertNull($r->tenant($this->request(['sub_institute_id' => self::T2])));
        $this->assertSame(401, $r->identity($this->request([]))->getStatusCode());
        $this->assertNull($r->tenant($this->request(['sub_institute_id' => self::T2], 'not-a-real-token')));

        $token = $this->token($this->user(self::T1));
        DB::table('personal_access_tokens')->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->assertNull($r->tenant($this->request(['sub_institute_id' => self::T1], $token)));
    }

    /**
     * CHARACTERIZATION, NOT ENDORSEMENT. An account whose own tbluser.sub_institute_id is NULL/0 is allowed to name
     * its organisation in the request (ResolvesApiIdentity.php, "historical rows predate tenant assignment").
     * Whether such accounts exist on live is unverified (needs a read-only count) - this test only pins today's
     * behaviour so a change to it is a deliberate, reviewed decision.
     */
    public function test_tenantless_account_may_name_a_tenant__known_residual_risk(): void
    {
        $token = $this->token($this->user(null));

        $this->assertSame(self::T2, $this->resolver()->tenant($this->request(['sub_institute_id' => self::T2], $token)));
        $this->assertSame(400, $this->resolver()->identity($this->request([], $token))->getStatusCode());
    }

    // ── F-135: the write path the audit would not demonstrate against live data ──

    public function test_compliance_delete_cannot_reach_another_tenants_record(): void
    {
        $mine = DB::table('master_compliance')->insertGetId(['sub_institute_id' => self::T1, 'title' => 'mine']);
        $theirs = DB::table('master_compliance')->insertGetId(['sub_institute_id' => self::T2, 'title' => 'theirs']);
        $token = $this->token($this->user(self::T1));

        // Tenant 1's user, naming tenant 2 in the body, aiming at tenant 2's row id.
        $request = $this->request([
            'type' => 'API', 'formName' => 'complaince_library', 'sub_institute_id' => self::T2, 'token' => $token,
        ], $token);
        $response = (new instituteDetailController())->destroy($request, $theirs);

        $this->assertNull(DB::table('master_compliance')->find($theirs)->deleted_at, "another tenant's record was deleted");
        $this->assertNull(DB::table('master_compliance')->find($mine)->deleted_at);
        $this->assertStringContainsString('Failed to Delete', $response->getContent());
    }

    public function test_compliance_delete_still_works_inside_the_callers_own_tenant(): void
    {
        $mine = DB::table('master_compliance')->insertGetId(['sub_institute_id' => self::T1, 'title' => 'mine']);
        $token = $this->token($this->user(self::T1));

        $request = $this->request(['type' => 'API', 'formName' => 'complaince_library', 'token' => $token], $token);
        $response = (new instituteDetailController())->destroy($request, $mine);

        $this->assertNotNull(DB::table('master_compliance')->find($mine)->deleted_at);
        $this->assertStringContainsString('Deleted SuccessFully', $response->getContent());
    }
}
