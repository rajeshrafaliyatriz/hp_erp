<?php

namespace Tests\Support;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The world the Simulation Learning feature tests run in, as the older suites build it inline: the token, user, profile and rights tables, the
 * simulation and asset tables, the Simulation Learning menu row and its rights, and a FAKE `digitalocean` disk (nothing here may reach the real
 * bucket). Call `bootSimulationWorld()` from setUp() and `tearDownSimulationWorld()` from tearDown().
 *
 * The byte builders make the smallest files a parser can tell apart: a binary glTF with a JSON part, and pictures that are only a header.
 */
trait SimulationTestWorld
{
    protected const T1 = 1;
    protected const T2 = 2;
    protected const ADMIN = 1;
    protected const EMPLOYEE = 2;
    protected const MENU = '/module/lms/simulation-learning';

    protected int $menuId;
    /** @var list<string> */
    protected array $tmp = [];

    protected function bootSimulationWorld(): void
    {
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
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->nullable()->default(0);
            $t->integer('level')->nullable()->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->tinyInteger('status')->default(1);
            $t->integer('sort_order')->default(1);
            $t->text('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->text('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
            $t->string('right_dashboard')->nullable();
        });

        (require base_path('database/migrations/2026_12_03_090000_create_g2g_simulation_tables.php'))->up();
        (require base_path('database/migrations/2026_12_04_090000_create_g2g_simulation_asset_table.php'))->up();

        DB::table('tbluserprofilemaster')->insert([
            ['id' => self::ADMIN, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => self::EMPLOYEE, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => 3, 'name' => 'Reporting Manager', 'role_key' => 'reporting_manager'],
            ['id' => 4, 'name' => 'Recruiter', 'role_key' => 'recruiter'],
        ]);
        $lms = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'LMS', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/lms', 'sort_order' => 4]);
        $builder = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'Course Builder', 'parent_id' => 76, 'level' => 3, 'access_link' => '/module/lms/administration/course-builder']);
        DB::table('tblmenumaster_g2g')->insert(['menu_name' => 'My Learning', 'parent_id' => 74, 'level' => 3, 'access_link' => '/module/lms/learning/my-learning']);
        DB::table('tblmenumaster_g2g')->insert(['menu_name' => 'Assessments', 'parent_id' => $lms, 'level' => 2, 'access_link' => '/module/lms/assessments', 'sort_order' => 7]);
        DB::table('tblgroupwise_rights_g2g')->insert(['menu_id' => $builder, 'profile_id' => self::ADMIN, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1, 'sub_institute_id' => '1']);
        (require base_path('database/migrations/2026_12_03_090100_create_simulation_learning_menu_row.php'))->up();
        $this->menuId = (int) DB::table('tblmenumaster_g2g')->where('access_link', self::MENU)->value('id');

        RoleKey::flushCache();
        Cache::flush();
        Storage::fake('digitalocean');
    }

    protected function tearDownSimulationWorld(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
        File::deleteDirectory(storage_path('framework/testing/disks/digitalocean'));
    }

    // ── people and rights ────────────────────────────────────────────────────

    /** Request headers for a new user of the organisation with the profile (the administrator unless said). */
    protected function h(int $tenant = self::T1, int $profile = self::ADMIN): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** Gives a profile these rights on the Simulation Learning page. */
    protected function allow(int $profile, array $bits): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(array_merge(['menu_id' => $this->menuId, 'profile_id' => $profile, 'sub_institute_id' => '1', 'can_view' => 0, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0], $bits));
        Cache::flush();
    }

    // ── the smallest files a parser can tell apart ───────────────────────────

    /** A binary glTF 2: header, a JSON part holding `$gltf` (a different `$label` is a different file) and, when given, a BIN part. */
    protected function glb(string $label = 'eye', array $gltf = [], ?string $bin = null): string
    {
        $json = json_encode($gltf + ['asset' => ['version' => '2.0'], 'extras' => ['label' => $label]], JSON_UNESCAPED_SLASHES);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
        $body = pack('V', strlen($json)) . 'JSON' . $json;
        if ($bin !== null) {
            $bin .= str_repeat("\0", (4 - strlen($bin) % 4) % 4);
            $body .= pack('V', strlen($bin)) . "BIN\0" . $bin;
        }

        return 'glTF' . pack('V', 2) . pack('V', 12 + strlen($body)) . $body;
    }

    /** A PNG that is only a signature and a header: enough to tell its size. A different `$label` (a comment chunk) is a different file. */
    protected function png(int $w = 8, int $h = 8, string $label = ''): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . ($label === '' ? '' : $chunk('tEXt', "Comment\0" . $label));
    }

    /** A JPEG with a frame header (so its size can be read). */
    protected function jpeg(int $w = 8, int $h = 8): string
    {
        return "\xFF\xD8\xFF\xE0" . pack('n', 16) . "JFIF\0\x01\x01\x00\x00\x01\x00\x01\x00\x00" . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('nn', $h, $w) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";
    }

    /** A WebP container with an extended header (so its size can be read). */
    protected function webp(int $w = 8, int $h = 8): string
    {
        $payload = "\0\0\0\0" . substr(pack('V', $w - 1), 0, 3) . substr(pack('V', $h - 1), 0, 3);
        $chunk = 'VP8X' . pack('V', strlen($payload)) . $payload;

        return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
    }

    /** A file on disk holding `$bytes`, removed at the end of the test. */
    protected function tmpFile(string $bytes, string $extension = ''): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'simw');
        $this->tmp[] = $file;
        if ($extension !== '') {
            rename($file, $file .= '.' . $extension);
            $this->tmp[] = $file;
        }
        file_put_contents($file, $bytes);

        return $file;
    }

    /** Registers a file the way an operator does (the command) and gives its sha256. */
    protected function register(?int $tenant, string $bytes, array $extra = []): string
    {
        $owner = $tenant === null ? ['--platform' => true] : ['--tenant' => $tenant];
        $code = Artisan::call('simulations:asset-add', ['file' => $this->tmpFile($bytes)] + $owner + $extra + ['--licence' => 'CC BY 4.0', '--credit' => 'Model by A. Author']);
        $this->assertSame(0, $code, Artisan::output());

        return hash('sha256', $bytes);
    }

    /** A valid g2g.sim/1 specification object that names these models (from the shipped example). */
    protected function specObject(string $id = 'eye-lab', ?array $models = null): \stdClass
    {
        $spec = json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')));
        $spec->id = $id;
        $spec->title = 'Eye lab';
        if ($models !== null) {
            $spec->assets = (object) ['models' => array_map(fn ($m) => (object) $m, $models)];
        }

        return $spec;
    }

    protected function model(string $sha, string $id = 'eye'): array
    {
        return ['id' => $id, 'sha256' => $sha, 'name' => 'Human eye'];
    }
}
