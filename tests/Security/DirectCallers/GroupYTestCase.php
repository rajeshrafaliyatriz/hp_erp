<?php

namespace Tests\Security\DirectCallers;

use App\Models\auth\tbluserModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Tests\Security\TokenTestCase;

/**
 * Base for the A-1c "Group Y" regression tests: the controllers that used to authenticate with Sanctum's plain `PersonalAccessToken::findToken` and now go
 * through `App\Support\PersonalAccessTokenResolver::findToken`.
 *
 * THE VULNERABILITY these tests protect against: the plain lookup only hash-verifies the secret. It does NOT refuse
 *   - a row named "Session Token" (what the removed provisioning code fabricated: abilities ["*"], 30-day expiry, bound to an administrator or to a user
 *     the REQUEST named) - it has a correct secret and a future expiry,
 *   - an EXPIRED token (these controllers never checked `expires_at`),
 *   - a token whose owner row no longer exists, or whose owner is not the application's user model.
 * At a site where the lookup is the only gate, any of those was therefore an authenticated caller.
 *
 * Same isolation as every test under tests/Security: in-memory SQLite, no env file read, run only with tests/Intelligence/bootstrap.php.
 */
abstract class GroupYTestCase extends TokenTestCase
{
    /** The six token kinds that must NOT authenticate (the keys of badTokens()); used to build one data-provider row per kind so a failure names its kind. */
    public const BAD_KINDS = [
        'unknown string',
        'guessed id (1|garbage)',
        'fabricated Session Token bound to an administrator',
        'expired legitimate token',
        'orphaned token (owner deleted)',
        'token owned by another model class',
    ];

    /** user 1 = tenant-1 administrator (profile 1), user 2 = tenant-2 employee (profile 2). Plain tokens of the legitimate flow: */
    protected tbluserModel $admin;
    protected tbluserModel $other;
    protected string $adminToken;
    protected string $otherToken;

    /** @var array<string, string>|null */
    private ?array $badTokenCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The global `api`/`web` group middleware RequireTwoFactorEnrolment reads these two tables for a recognised token. Without them it would raise a
        // QueryException BEFORE the controller runs and a "legitimate token" test could pass for the wrong reason. Empty tables = policy off.
        Schema::create('tenant_setting', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('setting_key', 96);
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('user_two_factor', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->text('secret')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
        [$this->admin, $this->other, $this->adminToken, $this->otherToken] = $this->twoTenants();
    }

    /** @return array<string, array{0: string}> one row per bad-token kind (the kind's name is the data-set name) */
    public static function kinds(): array
    {
        $rows = [];
        foreach (self::BAD_KINDS as $kind) {
            $rows[$kind] = [$kind];
        }

        return $rows;
    }

    /**
     * Every kind of token that must NOT authenticate at these sites, as kind => plain token string. Built once per test from real rows with a correct
     * secret wherever the scenario is "a stored row that plain Sanctum would accept".
     *
     * @return array<string, string>
     */
    protected function badTokens(): array
    {
        if ($this->badTokenCache !== null) {
            return $this->badTokenCache; // the fixtures it creates (users 3 and 4, token rows) must not be inserted twice
        }

        // 1. A fabricated row exactly as the removed provisioning code made it: name "Session Token", abilities ["*"], 30 days, bound to the ADMINISTRATOR.
        $fabricated = $this->admin->createToken('Session Token', ['*'], now()->addDays(30))->plainTextToken;

        // 2. A legitimate login token of the TENANT-1 administrator, expired a minute ago. Deliberately a tenant-1 owner: a test that names tenant 2 can then
        //    tell a pinned (accepted) caller from an anonymous one. The sites never checked expires_at.
        $expired = $this->admin->createToken('Chrome on Windows', ['*'], now()->subMinute())->plainTextToken;

        // 3. A token whose owner row has been deleted (orphan).
        $this->makeUser(3, 1, 1);
        $orphan = tbluserModel::query()->findOrFail(3)->createToken('Chrome on Windows', ['*'], now()->addDay())->plainTextToken;
        DB::table('tbluser')->where('id', 3)->delete();

        // 4. A token owned by a model that is not the application's user model (it even resolves: same table, tenant 1).
        $this->makeUser(4, 1, 1);
        $foreign = GroupYForeignOwner::query()->findOrFail(4)->createToken('Chrome on Windows', ['*'], now()->addDay())->plainTextToken;

        return $this->badTokenCache = [
            'unknown string' => 'definitely-not-a-token',
            'guessed id (1|garbage)' => '1|garbage',
            'fabricated Session Token bound to an administrator' => $fabricated,
            'expired legitimate token' => $expired,
            'orphaned token (owner deleted)' => $orphan,
            'token owned by another model class' => $foreign,
        ];
    }

    protected function badToken(string $kind): string
    {
        return $this->badTokens()[$kind];
    }

    /** Request-supplied identity that must never rescue an unrecognised token. @return list<array<string, mixed>> */
    protected function spoofs(): array
    {
        return [
            ['user_id' => 1, 'sub_institute_id' => 1],
            ['user_id' => 1, 'sub_institute_id' => 2, 'user_profile_name' => 'ADMIN'],
        ];
    }

    /** Minimal tables the sites' legitimate paths read, shaped so each site's post-authentication outcome is deterministic. */
    protected function createSiteTables(): void
    {
        Schema::create('gamma_api', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('account')->nullable();
            $t->string('key')->nullable();
            $t->integer('status')->nullable();
            $t->integer('limit')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
        });
        Schema::create('s_industries', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('industries')->nullable();
            $t->string('department')->nullable();
        });
        Schema::create('s_jobrole', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('track')->nullable();
            $t->string('jobrole')->nullable();
        });
        Schema::create('s_users_skills', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('title')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('s_user_skill_jobrole', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('skill')->nullable();
            $t->string('jobrole')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('sub_std_map', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('ai_course_outlines', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('course_type')->nullable();
            $t->text('input_fields')->nullable();
            $t->text('configure_fields')->nullable();
            $t->text('outline')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->createProfileTables();
    }

    /**
     * The tables and columns GET /api/user/profile reads. MySQL's CONCAT_WS does not exist in SQLite, so it is registered on the connection's PDO (the
     * statement itself is the controller's own, untouched).
     */
    private function createProfileTables(): void
    {
        $this->db()->getPdo()->sqliteCreateFunction('CONCAT_WS', static function (...$parts) {
            $separator = array_shift($parts);

            return implode((string) $separator, array_filter($parts, static fn ($v) => $v !== null && $v !== ''));
        }, -1);

        Schema::table('tbluser', function (Blueprint $t) {
            foreach (['first_name', 'middle_name', 'last_name', 'email', 'mobile', 'birthdate', 'address', 'gender', 'join_year', 'employee_no', 'employee_id', 'image', 'allocated_standards'] as $column) {
                $t->string($column)->nullable();
            }
        });
        Schema::table('school_setup', function (Blueprint $t) {
            foreach (['ShortCode', 'Logo', 'syear'] as $column) {
                $t->string($column)->nullable();
            }
        });
        Schema::create('hrms_departments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('department')->nullable();
        });
        Schema::create('s_user_jobrole', function (Blueprint $t) {
            $t->bigIncrements('id');
            foreach (['jobrole', 'job_level', 'sequence_order', 'has_vertical_progression', 'has_lateral_movement', 'progression_type'] as $column) {
                $t->string($column)->nullable();
            }
        });
        DB::table('school_setup')->insert(['id' => 1, 'SchoolName' => 'School One']);
        DB::table('school_setup')->insert(['id' => 2, 'SchoolName' => 'School Two']);
    }

    protected function snapshotAll(): string
    {
        $out = [];
        foreach (['personal_access_tokens', 'tbluser', 'gamma_api', 's_users_skills', 'sub_std_map', 'ai_course_outlines'] as $table) {
            $out[$table] = Schema::hasTable($table) ? DB::table($table)->get()->toArray() : 'missing';
        }

        return md5(json_encode($out));
    }
}

/** A model that is NOT App\Models\auth\tbluserModel but points at the same table: a token issued on it is "owned by some other model". */
class GroupYForeignOwner extends \Illuminate\Database\Eloquent\Model
{
    use HasApiTokens;

    protected $table = 'tbluser';
    public $timestamps = false;
}
