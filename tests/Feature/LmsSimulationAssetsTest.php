<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Services\Lms\SimulationException;
use App\Services\Lms\SimulationStore;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Simulation Learning, model assets: a 3D model is a PRIVATE file in the project's bucket (here a fake `digitalocean` disk, never the real
 * one) registered with its licence and credit; a specification names it by sha256; and the only way to the bytes is the API endpoint, which
 * answers for the caller's own organisation only. These pin that: what a specification may say about its models, what the command that adds
 * one will and will not accept and that it is idempotent, and that the endpoint streams the right bytes to the right people and to nobody else.
 */
class LmsSimulationAssetsTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;
    private const ADMIN = 1;
    private const EMPLOYEE = 2;
    private const MENU = '/module/lms/simulation-learning';

    private int $menuId;
    /** @var list<string> temporary files to remove */
    private array $tmp = [];

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
        Storage::fake('digitalocean');   // no test in this file may reach the real bucket
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
        File::deleteDirectory(storage_path('framework/testing/disks/digitalocean'));   // the fake disk, which may hold a 25 MB file
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1, int $profile = self::ADMIN): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function allow(int $profile, array $bits): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(array_merge(['menu_id' => $this->menuId, 'profile_id' => $profile, 'sub_institute_id' => '1', 'can_view' => 0, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0], $bits));
        Cache::flush();
    }

    /** A real (minimal) binary glTF 2: the 12-byte header and one JSON chunk. A different `$label` is a different file. */
    private function glb(string $label = 'eye'): string
    {
        $json = json_encode(['asset' => ['version' => '2.0'], 'extras' => ['label' => $label]]);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
        $chunk = pack('V', strlen($json)) . 'JSON' . $json;

        return 'glTF' . pack('V', 2) . pack('V', 12 + strlen($chunk)) . $chunk;
    }

    /** A file on disk holding `$bytes`; named with `$extension` when one is given (the name a file carries is not what it is). */
    private function file(string $bytes, string $extension = ''): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'glb');
        $this->tmp[] = $file;
        if ($extension !== '') {
            rename($file, $file .= '.' . $extension);
            $this->tmp[] = $file;
        }
        file_put_contents($file, $bytes);

        return $file;
    }

    /** Runs simulations:asset-add and gives its exit code. */
    private function add(string $bytes, array $options = [], string $extension = ''): int
    {
        return Artisan::call('simulations:asset-add', ['file' => $this->file($bytes, $extension)] + $options);
    }

    /** Adds a model the way an operator does and gives its sha256. */
    private function register(?int $tenant, string $bytes, array $extra = []): string
    {
        $owner = $tenant === null ? ['--platform' => true] : ['--tenant' => $tenant];
        $this->assertSame(0, $this->add($bytes, $owner + $extra + ['--licence' => 'CC BY 4.0', '--credit' => 'Eye model by A. Author', '--source' => 'https://example.org/eye']), Artisan::output());

        return hash('sha256', $bytes);
    }

    private function specObject(string $id = 'eye-lab', ?array $models = null): \stdClass
    {
        $spec = json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')));
        $spec->id = $id;
        $spec->title = 'Eye lab';
        if ($models !== null) {
            $spec->assets = (object) ['models' => array_map(fn ($m) => (object) $m, $models)];
        }

        return $spec;
    }

    private function model(string $sha, string $id = 'eye', ?string $name = 'Human eye'): array
    {
        return ['id' => $id, 'sha256' => $sha] + ($name === null ? [] : ['name' => $name]);
    }

    /** Adds a simulation that names the given models, through the API as that caller, and gives its id. */
    private function simulation(array $headers, array $models, string $id = 'eye-lab', array $extra = []): int
    {
        return (int) $this->postJson('/api/lms/simulations', ['spec' => $this->specObject($id, $models)] + $extra, $headers)->assertCreated()->json('data.id');
    }

    /**
     * A simulation as it can exist although saving would now refuse it: written to the tables directly, the way a version saved BEFORE saving
     * checked its files is, or one whose file has been removed since. (SimulationStore::create() refuses a model its owner cannot use; what is
     * served and credited must still refuse such a hash, because rows like this exist.) Gives its id.
     */
    private function unchecked(?int $tenant, string $id, array $models): int
    {
        $json = (new SimulationStore())->parse(json_encode($this->specObject($id, $models)))['json'];
        $sim = DB::table('g2g_simulation')->insertGetId(['sub_institute_id' => $tenant, 'slug' => $id, 'title' => 'Eye lab', 'status' => 'published', 'current_version' => 1, 'missions' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('g2g_simulation_version')->insert(['simulation_id' => $sim, 'version' => 1, 'spec' => $json, 'sha256' => hash('sha256', $json), 'created_at' => now()]);

        return $sim;
    }

    private function url(int $sim, string $sha): string
    {
        return "/api/lms/simulations/{$sim}/assets/{$sha}";
    }

    /** The `put` calls the disk receives, as [path, contents, options], while still really storing the file on the fake disk. */
    private function recordPuts(): \ArrayObject
    {
        $fake = Storage::fake('digitalocean');
        $puts = new \ArrayObject();
        $recorder = \Mockery::mock($fake);
        $recorder->shouldReceive('put')->andReturnUsing(function (...$args) use ($fake, $puts) {
            $puts[] = $args;

            return $fake->put(...$args);
        });
        Storage::set('digitalocean', $recorder);

        return $puts;
    }

    private function problemsOf(\stdClass $spec): string
    {
        try {
            (new SimulationStore())->parse(json_encode($spec));
        } catch (SimulationException $e) {
            return implode(' | ', $e->problems);
        }

        return '';
    }

    // ── what a specification may say about its models ────────────────────────

    public function test_a_specification_may_name_models_and_the_store_reports_which_files_it_needs(): void
    {
        $a = str_repeat('a', 64);
        $b = hash('sha256', 'b');
        $parsed = (new SimulationStore())->parse(json_encode($this->specObject('eye-lab', [$this->model($a), $this->model($b, 'lens', null), $this->model($a, 'eye-again')])));
        $this->assertSame([$a, $b], $parsed['facts']['assets'], 'in order, and a file used twice is one file');

        $plain = (new SimulationStore())->parse((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')));
        $this->assertSame([], $plain['facts']['assets'], 'a specification with no models needs no files');
        $this->assertArrayNotHasKey('assets', json_decode($plain['json'], true), 'and nothing is added to it');

        foreach ([(object) [], (object) ['models' => []]] as $empty) {
            $spec = $this->specObject();
            $spec->assets = $empty;
            $this->assertSame([], (new SimulationStore())->parse(json_encode($spec))['facts']['assets']);
        }

        $most = array_map(fn ($n) => $this->model(hash('sha256', (string) $n), 'model-' . $n), range(1, 40));
        $this->assertCount(40, (new SimulationStore())->parse(json_encode($this->specObject('eye-lab', $most)))['facts']['assets'], 'forty is the most');
    }

    public function test_malformed_models_are_refused_with_every_problem_named(): void
    {
        $sha = str_repeat('c', 64);
        $tooMany = array_map(fn ($n) => $this->model(hash('sha256', (string) $n), 'model-' . $n), range(1, 41));
        $cases = [
            ['"assets" must be an object', fn (\stdClass $s) => $s->assets = 'eye'],
            ['"assets" must be an object', fn (\stdClass $s) => $s->assets = [$this->model($sha)]],
            ['"assets.models" must be a list of up to 40', fn (\stdClass $s) => $s->assets = (object) ['models' => 'eye']],
            ['"assets.models" must be a list of up to 40', fn (\stdClass $s) => $s->assets = (object) ['models' => (object) ['eye' => $this->model($sha)]]],
            ['"assets.models" must be a list of up to 40', fn (\stdClass $s) => $s->assets = (object) ['models' => array_map(fn ($m) => (object) $m, $tooMany)]],
            ['"assets.models.0" must be an object', fn (\stdClass $s) => $s->assets = (object) ['models' => ['eye']]],
            ['"assets.models.0.id" is required', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) ['sha256' => $sha]]]],
            ['"assets.models.0.id" is lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model($sha, 'Eye')]]],
            ['"assets.models.0.id" is lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model($sha, '3d-eye')]]],
            ['"assets.models.0.id" must be text', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model($sha, str_repeat('e', 33))]]],
            ['"assets.models.1.id" is "eye", which another model already uses', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model($sha), (object) $this->model(str_repeat('d', 64))]]],
            ['"assets.models.0.sha256" must be 64 lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) ['id' => 'eye']]]],
            ['"assets.models.0.sha256" must be 64 lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model(strtoupper($sha))]]],
            ['"assets.models.0.sha256" must be 64 lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model(substr($sha, 1))]]],
            ['"assets.models.0.sha256" must be 64 lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model($sha . 'c')]]],
            ['"assets.models.0.sha256" must be 64 lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) $this->model(str_repeat('g', 64))]]],
            ['"assets.models.0.sha256" must be 64 lowercase', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) ['id' => 'eye', 'sha256' => 5]]]],
            ['"assets.models.0.name" must be text', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) (['name' => 7] + $this->model($sha))]]],
            ['"assets.models.0.name" must be text', fn (\stdClass $s) => $s->assets = (object) ['models' => [(object) ['id' => 'eye', 'sha256' => $sha, 'name' => str_repeat('n', 121)]]]],
        ];
        foreach ($cases as $n => [$needle, $mutate]) {
            $spec = $this->specObject();
            $mutate($spec);
            $this->assertStringContainsString($needle, $this->problemsOf($spec), "case {$n}");
        }

        // every problem is listed, not just the first, and in the same list as the problems elsewhere in the specification
        $spec = $this->specObject();
        $spec->title = '';
        $spec->assets = (object) ['models' => [(object) ['id' => 'Eye', 'sha256' => 'x'], 'eye']];
        $problems = $this->problemsOf($spec);
        foreach (['"title"', '"assets.models.0.id"', '"assets.models.0.sha256"', '"assets.models.1"'] as $needle) {
            $this->assertStringContainsString($needle, $problems);
        }

        $r = $this->postJson('/api/lms/simulations', ['spec' => $spec], $this->h())->assertStatus(422);
        $this->assertStringContainsString('"assets.models.0.sha256"', implode(' | ', $r->json('problems')));
        $this->assertSame(0, DB::table('g2g_simulation')->count());
    }

    // ── simulations:asset-add ────────────────────────────────────────────────

    public function test_the_command_uploads_privately_to_the_right_path_and_registers_the_file(): void
    {
        $puts = $this->recordPuts();
        $bytes = $this->glb();
        $sha = hash('sha256', $bytes);
        $file = $this->file($bytes);

        $code = Artisan::call('simulations:asset-add', ['file' => $file, '--tenant' => 6, '--name' => 'Human eye', '--licence' => 'CC BY 4.0', '--credit' => 'Eye model by A. Author', '--source' => 'https://example.org/eye', '--user' => 41]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $path = "sim_assets/6/{$sha}.glb";
        $this->assertSame($bytes, Storage::disk('digitalocean')->get($path), 'the bytes are on the disk, exactly');
        $this->assertCount(1, $puts);
        $this->assertSame($path, $puts[0][0]);
        $this->assertSame('private', $puts[0][2]['visibility'], 'with no public ACL');

        $row = DB::table('g2g_simulation_asset')->first();
        $this->assertSame([6, $sha, 'Human eye', 'model', 'model/gltf-binary', strlen($bytes), $path, 'CC BY 4.0', 'Eye model by A. Author', 'https://example.org/eye', 41],
            [(int) $row->sub_institute_id, $row->sha256, $row->name, $row->kind, $row->mime, (int) $row->bytes, $row->storage_path, $row->licence, $row->credit, $row->source_url, (int) $row->created_by]);
        $this->assertNull($row->deleted_at);

        $this->assertStringContainsString($sha, $out);
        $this->assertStringContainsString($path, $out);
        $this->assertStringContainsString('{"id": "human-eye", "sha256": "' . $sha . '", "name": "Human eye"}', $out, 'the snippet to paste into a specification');

        // as printed, it is valid JSON and a specification accepts it
        preg_match('/^\s*(\{"id": .*\})\s*$/m', $out, $m);
        $snippet = json_decode($m[1], true);
        $this->assertSame(['id' => 'human-eye', 'sha256' => $sha, 'name' => 'Human eye'], $snippet);
        $this->assertSame([$sha], (new SimulationStore())->parse(json_encode($this->specObject('eye-lab', [$snippet])))['facts']['assets']);
    }

    public function test_a_platform_asset_has_no_owner_and_its_own_folder(): void
    {
        $bytes = $this->glb('platform');
        $sha = $this->register(null, $bytes);

        $this->assertNull(DB::table('g2g_simulation_asset')->value('sub_institute_id'));
        $this->assertSame("sim_assets/platform/{$sha}.glb", DB::table('g2g_simulation_asset')->value('storage_path'));
        Storage::disk('digitalocean')->assertExists("sim_assets/platform/{$sha}.glb");
    }

    public function test_the_name_defaults_to_the_file_name_and_the_id_in_the_snippet_is_always_a_valid_one(): void
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('glbname');
        mkdir($dir);
        $file = $dir . '/Human Eye v2.glb';
        file_put_contents($file, $this->glb('named'));

        $this->assertSame(0, Artisan::call('simulations:asset-add', ['file' => $file, '--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']));
        $this->assertSame('Human Eye v2', DB::table('g2g_simulation_asset')->value('name'));
        $this->assertStringContainsString('"id": "human-eye-v2"', Artisan::output());
        @unlink($file);
        @rmdir($dir);

        foreach (['3D eye' => 'm-3d-eye', '???' => 'm', 'Ünï cödé' => 'uni-code', str_repeat('long ', 20) => 'long-long-long-long-long-long-lo'] as $name => $expected) {
            $this->assertSame(0, $this->add($this->glb($name), ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x', '--name' => $name]));
            preg_match('/"id": "([^"]+)"/', Artisan::output(), $m);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]{0,31}$/', $m[1], "id for \"{$name}\"");
            $this->assertSame($expected, $m[1]);
        }
    }

    public function test_the_command_refuses_a_file_that_is_not_a_binary_gltf(): void
    {
        $good = $this->glb();
        $bad = [
            'text' => 'this is not a model',
            'json glTF' => '{"asset":{"version":"2.0"}}',
            'empty' => '',
            'only the magic' => 'glTF',
            'a short header' => 'glTF' . pack('V', 2),
            'version 1' => 'glTF' . pack('V', 1) . substr($good, 8),
            'version 3' => 'glTF' . pack('V', 3) . substr($good, 8),
            'wrong magic' => 'GLTF' . substr($good, 4),
        ];
        foreach ($bad as $why => $bytes) {
            $this->assertSame(1, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']), $why);
        }
        $this->assertStringContainsString('version 3', $this->runFor('glTF' . pack('V', 3) . substr($good, 8)));
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles(), 'nothing was uploaded');
    }

    /** The output of one failing run. */
    private function runFor(string $bytes): string
    {
        $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']);

        return Artisan::output();
    }

    public function test_the_command_refuses_a_header_that_declares_a_different_length(): void
    {
        $good = $this->glb();
        foreach ([strlen($good) - 4, strlen($good) + 4, 0] as $declared) {
            $bytes = substr($good, 0, 8) . pack('V', $declared) . substr($good, 12);
            $this->assertSame(1, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']), "declares {$declared}");
            $this->assertStringContainsString("declares {$declared} bytes", Artisan::output());
        }
        $this->assertSame(1, $this->add($good . 'trailing', ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']), 'something added after the end');
        $this->assertSame(1, $this->add(substr($good, 0, -4), ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']), 'cut short');
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());
    }

    public function test_the_command_refuses_a_model_without_its_licence_or_credit(): void
    {
        $bytes = $this->glb();
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--credit' => 'x']), 'no licence');
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0']), 'no credit');
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--licence' => '  ', '--credit' => 'x']), 'a blank licence');
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => '']), 'a blank credit');
        $this->assertSame(2, $this->add($bytes, ['--platform' => true]), 'neither');
        $this->assertStringContainsString('licence and credit', Artisan::output());
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());
    }

    public function test_the_command_wants_exactly_one_owner_and_sane_details(): void
    {
        $bytes = $this->glb();
        $ok = ['--licence' => 'CC0', '--credit' => 'x'];
        $this->assertSame(2, $this->add($bytes, $ok), 'neither --tenant nor --platform');
        $this->assertSame(2, $this->add($bytes, $ok + ['--tenant' => 6, '--platform' => true]), 'both');
        $this->assertSame(2, $this->add($bytes, $ok + ['--tenant' => 'six']), 'a tenant that is not an id');
        $this->assertSame(2, $this->add($bytes, $ok + ['--tenant' => 6, '--source' => 'javascript:alert(1)']), 'a source that is not an https address');
        $this->assertSame(2, $this->add($bytes, $ok + ['--tenant' => 6, '--source' => 'http://example.org/eye']));
        $this->assertSame(2, $this->add($bytes, $ok + ['--tenant' => 6, '--name' => str_repeat('n', 121)]));
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--licence' => str_repeat('l', 81), '--credit' => 'x']));
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => str_repeat('c', 301)]));
        $this->assertSame(2, $this->add($bytes, $ok + ['--tenant' => 6, '--user' => 'me']));
        $this->assertSame(2, Artisan::call('simulations:asset-add', ['file' => sys_get_temp_dir() . '/there-is-no-such-file.glb', '--tenant' => 6] + $ok), 'no such file');
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());

        // and the limits themselves are allowed
        $this->assertSame(0, $this->add($bytes, ['--tenant' => 6, '--licence' => str_repeat('l', 80), '--credit' => str_repeat('c', 300), '--name' => str_repeat('n', 120)]));
    }

    public function test_the_command_refuses_a_file_over_25_mb(): void
    {
        $size = SimulationStore::MAX_ASSET_BYTES + 1;
        $bytes = 'glTF' . pack('V', 2) . pack('V', $size) . str_repeat("\0", $size - 12);   // a good header: only the size is wrong
        $this->assertSame(1, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']));
        $this->assertStringContainsString('25 MB', Artisan::output());

        $size = SimulationStore::MAX_ASSET_BYTES;
        $exact = 'glTF' . pack('V', 2) . pack('V', $size) . str_repeat("\0", $size - 12);
        $this->assertSame(0, $this->add($exact, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']), 'exactly 25 MB is allowed');
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count());
    }

    public function test_running_the_command_again_for_the_same_file_reports_it_and_changes_nothing(): void
    {
        $puts = $this->recordPuts();
        $bytes = $this->glb();
        $sha = $this->register(6, $bytes);

        $this->assertSame(0, $this->add($bytes, ['--tenant' => 6, '--licence' => 'MIT', '--credit' => 'someone else']));
        $this->assertStringContainsString('Already registered as asset 1', Artisan::output());
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count());
        $this->assertCount(1, $puts, 'the file is not uploaded a second time');
        $this->assertSame(['CC BY 4.0', 'Eye model by A. Author'], [DB::table('g2g_simulation_asset')->value('licence'), DB::table('g2g_simulation_asset')->value('credit')], 'and what was registered is left as it was');

        // the same file for another organisation, and for the platform, is a registration of its own
        $this->register(7, $bytes);
        $this->register(null, $bytes);
        $this->assertSame(3, DB::table('g2g_simulation_asset')->count());
        $this->assertCount(3, $puts);
        $this->assertSame(['sim_assets/6/' . $sha . '.glb', 'sim_assets/7/' . $sha . '.glb', 'sim_assets/platform/' . $sha . '.glb'], array_column($puts->getArrayCopy(), 0));
        $this->register(null, $bytes);
        $this->assertSame(3, DB::table('g2g_simulation_asset')->count(), 'a platform asset is not registered twice either');
    }

    public function test_the_command_puts_a_file_back_that_has_gone_missing_from_the_disk(): void
    {
        $bytes = $this->glb();
        $sha = $this->register(6, $bytes);
        $path = "sim_assets/6/{$sha}.glb";
        Storage::disk('digitalocean')->delete($path);

        $this->assertSame(0, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x']));
        $this->assertStringContainsString('uploaded again', Artisan::output());
        $this->assertSame($bytes, Storage::disk('digitalocean')->get($path));
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count());
    }

    public function test_a_file_already_on_the_disk_is_registered_without_being_uploaded_again(): void
    {
        $puts = $this->recordPuts();
        $bytes = $this->glb();
        $sha = hash('sha256', $bytes);
        Storage::disk('digitalocean')->put("sim_assets/6/{$sha}.glb", $bytes);
        $puts->exchangeArray([]);   // that was the set-up, not the command

        $this->register(6, $bytes);
        $this->assertCount(0, $puts);
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count());
    }

    public function test_an_asset_that_was_removed_is_restored_by_adding_it_again(): void
    {
        $bytes = $this->glb();
        $this->register(6, $bytes);
        DB::table('g2g_simulation_asset')->update(['deleted_at' => now()]);

        $this->assertSame(0, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'new credit']));
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count(), 'not a second row (the unique index would refuse it)');
        $row = DB::table('g2g_simulation_asset')->first();
        $this->assertNull($row->deleted_at);
        $this->assertSame(['CC0', 'new credit'], [$row->licence, $row->credit], 'with the details given now');
    }

    public function test_a_dry_run_checks_and_shows_everything_and_writes_nothing(): void
    {
        $puts = $this->recordPuts();
        $bytes = $this->glb();
        $sha = hash('sha256', $bytes);

        $this->assertSame(0, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x', '--name' => 'Human eye', '--dry-run' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString($sha, $out);
        $this->assertStringContainsString("sim_assets/6/{$sha}.glb", $out);
        $this->assertStringContainsString('{"id": "human-eye", "sha256": "' . $sha . '", "name": "Human eye"}', $out);
        $this->assertStringContainsString('nothing was uploaded or written', $out);
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertCount(0, $puts);
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());

        // it still refuses what the real run would refuse
        $this->assertSame(1, $this->add('not a model', ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x', '--dry-run' => true]));
        $this->assertSame(2, $this->add($bytes, ['--tenant' => 6, '--dry-run' => true]));

        // and on a file that is registered it says so
        $this->register(6, $bytes);
        $this->assertSame(0, $this->add($bytes, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x', '--dry-run' => true]));
        $this->assertStringContainsString('Already registered as asset 1', Artisan::output());
    }

    public function test_the_command_fails_cleanly_when_the_disk_cannot_be_written(): void
    {
        $this->assertSame(1, $this->add($this->glb(), ['--tenant' => 6, '--licence' => 'CC0', '--credit' => 'x', '--disk' => 'there-is-no-such-disk']));
        $this->assertStringContainsString('Could not put the file on the disk "there-is-no-such-disk"', Artisan::output());
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count(), 'a row that points at nothing is never written');
    }

    // ── GET /api/lms/simulations/{id}/assets/{sha256} ────────────────────────

    public function test_the_owner_organisation_gets_the_exact_bytes_with_the_right_headers(): void
    {
        $bytes = $this->glb();
        $sha = $this->register(self::T1, $bytes);
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);

        $r = $this->get($this->url($sim, $sha), $admin)->assertOk();
        $this->assertSame($bytes, $r->streamedContent());
        $this->assertSame('model/gltf-binary', $r->headers->get('Content-Type'));
        $this->assertSame((string) strlen($bytes), $r->headers->get('Content-Length'));
        $this->assertSame('"' . $sha . '"', $r->headers->get('ETag'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $cache = (string) $r->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringNotContainsString('sim_assets', json_encode($r->headers->all()), 'the storage path is not in any header');
    }

    public function test_a_learner_who_may_view_gets_it_too(): void
    {
        $bytes = $this->glb();
        $sha = $this->register(self::T1, $bytes);
        $sim = $this->simulation($this->h(self::T1), [$this->model($sha)]);
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);

        $this->assertSame($bytes, $this->get($this->url($sim, $sha), $this->h(self::T1, self::EMPLOYEE))->assertOk()->streamedContent());
    }

    public function test_a_platform_asset_is_served_to_another_organisations_simulation_that_names_it(): void
    {
        $bytes = $this->glb('platform');
        $sha = $this->register(null, $bytes);
        $t2 = $this->h(self::T2);
        $sim = $this->simulation($t2, [$this->model($sha)]);

        $this->assertSame($bytes, $this->get($this->url($sim, $sha), $t2)->assertOk()->streamedContent());

        // and a platform simulation, once an organisation has switched it on
        $platform = (int) (new SimulationStore())->create(null, null, json_encode($this->specObject('shared-eye', [$this->model($sha)])), ['status' => 'published'])->id;
        $this->get($this->url($platform, $sha), $t2)->assertNotFound();
        $this->postJson("/api/lms/simulations/{$platform}/enable", [], $t2)->assertOk();
        $this->assertSame($bytes, $this->get($this->url($platform, $sha), $t2)->assertOk()->streamedContent());
    }

    public function test_a_platform_simulation_can_use_platform_assets_only(): void
    {
        $own = $this->glb('t1');
        $shaOwn = $this->register(self::T1, $own);
        $platform = $this->unchecked(null, 'shared-eye', [$this->model($shaOwn)]);   // saving refuses this now; an older version may still be like it
        $t1 = $this->h(self::T1);
        $this->postJson("/api/lms/simulations/{$platform}/enable", [], $t1)->assertOk();

        $this->get($this->url($platform, $shaOwn), $t1)->assertNotFound();   // a platform simulation cannot hand out one organisation's file
        $this->assertSame([], $this->getJson("/api/lms/simulations/{$platform}", $t1)->assertOk()->json('data.assets'));
    }

    public function test_an_unchanged_file_answers_304_and_no_bytes(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);

        foreach (['"' . $sha . '"', 'W/"' . $sha . '"', '"other", "' . $sha . '"', '*'] as $sent) {
            $r = $this->get($this->url($sim, $sha), $admin + ['If-None-Match' => $sent])->assertStatus(304);
            $this->assertSame('', (string) $r->getContent(), "for {$sent}");
            $this->assertSame('"' . $sha . '"', $r->headers->get('ETag'));
            $this->assertStringContainsString('max-age=86400', (string) $r->headers->get('Cache-Control'));
        }
        $this->get($this->url($sim, $sha), $admin + ['If-None-Match' => '"' . str_repeat('0', 64) . '"'])->assertOk();   // another version: the whole file
    }

    public function test_a_hash_the_specification_does_not_name_is_not_served(): void
    {
        $named = $this->register(self::T1, $this->glb('named'));
        $other = $this->register(self::T1, $this->glb('registered but not named'));
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($named)]);

        $this->get($this->url($sim, $other), $admin)->assertNotFound()->assertJsonPath('status', false);
        $this->get($this->url($sim, str_repeat('0', 64)), $admin)->assertNotFound();
        $this->get($this->url($sim, $named), $admin)->assertOk();
    }

    public function test_only_the_current_version_of_the_specification_counts(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);
        $this->get($this->url($sim, $sha), $admin)->assertOk();

        $this->putJson("/api/lms/simulations/{$sim}", ['spec' => $this->specObject('eye-lab')], $admin)->assertOk()->assertJsonPath('data.version', 2);
        $this->get($this->url($sim, $sha), $admin)->assertNotFound();
        $this->get($this->url($sim, $sha) . '?version=1', $admin)->assertNotFound();
    }

    public function test_a_kept_version_is_checked_again_when_it_is_switched_on(): void
    {
        $a = $this->register(self::T1, $this->glb('a'));
        $b = $this->register(self::T1, $this->glb('b'));
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($a)]);
        $store = new SimulationStore();
        $row = fn () => DB::table('g2g_simulation')->where('id', $sim)->first();

        // version 2 names another file and is only kept: the files of version 1 are the ones served, and the new one is not yet
        $store->addVersion($row(), null, json_encode($this->specObject('eye-lab', [$this->model($b)])), 'kept', false);
        $this->assertSame(1, (int) $row()->current_version);
        $this->get($this->url($sim, $a), $admin)->assertOk();
        $this->get($this->url($sim, $b), $admin)->assertNotFound();

        // the file is removed before the switch: it is refused, as a save would be, and nothing moves
        DB::table('g2g_simulation_asset')->where('sha256', $b)->update(['deleted_at' => now()]);
        try {
            $store->promote($row(), null, 2);
            $this->fail('a version whose file has been removed must not be switched on');
        } catch (SimulationException $e) {
            $this->assertStringContainsString('has not been uploaded for this organisation, or it has been removed', implode(' ', $e->problems));
        }
        $this->assertSame(1, (int) $row()->current_version);

        // with the file back it is switched on, and then it is the files of version 2 that are served
        DB::table('g2g_simulation_asset')->where('sha256', $b)->update(['deleted_at' => null]);
        $this->assertSame(2, (int) $store->promote($row(), null, 2)->current_version);
        $this->get($this->url($sim, $b), $admin)->assertOk();
        $this->get($this->url($sim, $a), $admin)->assertNotFound();
    }

    public function test_the_route_takes_a_lowercase_sha256_of_64_characters_only(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);

        foreach ([strtoupper($sha), substr($sha, 1), $sha . '0', str_repeat('g', 64), '..%2F..%2F.env'] as $bad) {
            $this->get($this->url($sim, $bad), $admin)->assertNotFound();
        }
    }

    public function test_another_organisations_simulation_and_files_are_never_served(): void
    {
        $bytes = $this->glb('t1 only');
        $sha = $this->register(self::T1, $bytes);
        $t1 = $this->h(self::T1);
        $sim = $this->simulation($t1, [$this->model($sha)]);
        $t2 = $this->h(self::T2);

        // the simulation is not theirs
        $this->get($this->url($sim, $sha), $t2)->assertNotFound();

        // and they cannot get the file by writing the hash into a simulation of their own: saving refuses it ...
        $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('my-eye', [$this->model($sha)])], $t2)->assertStatus(422);
        // ... and if such a version existed anyway (written before saving checked), the asset is not theirs or the platform's, so it is not served
        $mine = $this->unchecked(self::T2, 'my-eye', [$this->model($sha)]);
        $this->get($this->url($mine, $sha), $t2)->assertNotFound();
        $shown = $this->getJson("/api/lms/simulations/{$mine}", $t2)->assertOk();
        $this->assertSame([], $shown->json('data.assets'), 'and no credit for it either');
        $this->assertStringNotContainsString('Eye model by A. Author', $shown->getContent());

        $this->get($this->url($sim, $sha), $t1)->assertOk();   // the owner is unaffected
    }

    public function test_a_draft_is_for_managers_only(): void
    {
        $bytes = $this->glb();
        $sha = $this->register(self::T1, $bytes);
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)], 'eye-lab', ['status' => 'draft']);
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);

        $this->get($this->url($sim, $sha), $this->h(self::T1, self::EMPLOYEE))->assertNotFound();
        $this->assertSame($bytes, $this->get($this->url($sim, $sha), $admin)->assertOk()->streamedContent());

        $this->putJson("/api/lms/simulations/{$sim}", ['status' => 'published'], $admin)->assertOk();
        $this->get($this->url($sim, $sha), $this->h(self::T1, self::EMPLOYEE))->assertOk();
    }

    public function test_a_removed_simulation_or_asset_is_not_served(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);

        DB::table('g2g_simulation_asset')->update(['deleted_at' => now()]);
        $this->get($this->url($sim, $sha), $admin)->assertNotFound();
        DB::table('g2g_simulation_asset')->update(['deleted_at' => null]);
        $this->get($this->url($sim, $sha), $admin)->assertOk();

        $this->deleteJson("/api/lms/simulations/{$sim}", [], $admin)->assertOk();
        $this->get($this->url($sim, $sha), $admin)->assertNotFound();
    }

    public function test_a_file_missing_from_the_disk_is_a_404_not_a_server_error(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);
        Storage::disk('digitalocean')->delete("sim_assets/1/{$sha}.glb");

        $this->get($this->url($sim, $sha), $admin)->assertNotFound()->assertJsonPath('status', false);
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count(), 'the registration is still there to be put right with the command');
    }

    public function test_the_endpoint_needs_a_token_and_the_view_right(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $sim = $this->simulation($this->h(self::T1), [$this->model($sha)]);

        $this->json('GET', $this->url($sim, $sha))->assertStatus(401);
        $this->get($this->url($sim, $sha), $this->h(self::T1, self::EMPLOYEE))->assertForbidden();   // a learner with no right on the menu row
    }

    public function test_the_organisation_and_the_asset_in_the_request_are_ignored(): void
    {
        $sha = $this->register(self::T1, $this->glb());
        $sim = $this->simulation($this->h(self::T1), [$this->model($sha)]);
        $t2 = $this->h(self::T2);

        $this->get($this->url($sim, $sha) . '?sub_institute_id=' . self::T1, $t2)->assertNotFound();
    }

    // ── show: the credits ────────────────────────────────────────────────────

    public function test_show_lists_the_credits_of_the_models_that_are_registered(): void
    {
        $a = $this->register(self::T1, $this->glb('a'), ['--name' => 'Human eye']);
        $b = $this->register(self::T1, $this->glb('b'), ['--name' => 'Lens']);
        $removed = $this->register(self::T1, $this->glb('removed'));
        $unregistered = hash('sha256', 'never added');
        DB::table('g2g_simulation_asset')->where('sha256', $removed)->update(['deleted_at' => now()]);
        $admin = $this->h(self::T1);
        // saving refuses a file that is not registered or has been removed; a version saved earlier, or a file removed since, can still be like that
        $sim = $this->unchecked(self::T1, 'eye-lab', [$this->model($b, 'lens'), $this->model($a), $this->model($removed, 'gone'), $this->model($unregistered, 'never')]);

        $r = $this->getJson("/api/lms/simulations/{$sim}", $admin)->assertOk();
        $assets = $r->json('data.assets');
        $this->assertCount(2, $assets, 'a reference with no registered asset is skipped');
        $this->assertSame([$b, $a], array_column($assets, 'sha256'), 'in the order the specification names them');
        $this->assertSame(['sha256', 'kind', 'name', 'bytes', 'licence', 'credit', 'source_url'], array_keys($assets[0]));
        $this->assertSame('model', $assets[0]['kind']);
        $this->assertSame(['Lens', strlen($this->glb('b')), 'CC BY 4.0', 'Eye model by A. Author', 'https://example.org/eye'], [$assets[0]['name'], $assets[0]['bytes'], $assets[0]['licence'], $assets[0]['credit'], $assets[0]['source_url']]);
        $this->assertStringNotContainsString('sim_assets', $r->getContent(), 'the storage path is never exposed');
        $this->assertStringNotContainsString('storage_path', $r->getContent());

        // everything else about the response is as it was
        $this->assertSame('eye-lab', $r->json('data.spec.id'));
        $this->assertSame($sim, $r->json('data.id'));
        $this->assertSame(['view' => true, 'add' => true, 'edit' => true, 'delete' => true], $r->json('data.can'));
    }

    public function test_show_has_an_empty_list_of_assets_for_a_simulation_without_models(): void
    {
        $admin = $this->h(self::T1);
        $sim = (int) $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('plain')], $admin)->assertCreated()->json('data.id');

        $this->getJson("/api/lms/simulations/{$sim}", $admin)->assertOk()->assertJsonPath('data.assets', [])->assertJsonPath('data.spec.id', 'plain');
    }

    public function test_the_organisations_own_registration_is_the_one_credited_when_the_platform_holds_the_same_file(): void
    {
        $bytes = $this->glb('both');
        $sha = $this->register(null, $bytes, ['--credit' => 'Platform credit']);
        $this->register(self::T1, $bytes, ['--credit' => 'Own credit']);
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);
        $t2 = $this->h(self::T2);
        $other = $this->simulation($t2, [$this->model($sha)], 'other');

        $this->assertSame('Own credit', $this->getJson("/api/lms/simulations/{$sim}", $admin)->json('data.assets.0.credit'));
        $this->assertSame($bytes, $this->get($this->url($sim, $sha), $admin)->assertOk()->streamedContent());
        $this->assertSame('Platform credit', $this->getJson("/api/lms/simulations/{$other}", $t2)->json('data.assets.0.credit'), 'an organisation with no registration of its own gets the platform\'s');
    }

    public function test_a_credit_that_is_missing_is_not_a_blank_one_for_a_row_added_by_hand(): void
    {
        // the command refuses a model with no licence or credit; a row inserted by other means may still have none, and the screen is told so
        $sha = $this->register(self::T1, $this->glb());
        DB::table('g2g_simulation_asset')->update(['licence' => null, 'credit' => null, 'source_url' => null]);
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($sha)]);

        $asset = $this->getJson("/api/lms/simulations/{$sim}", $admin)->assertOk()->json('data.assets.0');
        $this->assertSame([null, null, null], [$asset['licence'], $asset['credit'], $asset['source_url']]);
    }

    // ── images beside the models ─────────────────────────────────────────────

    /** A PNG that is a real picture (1 x 1), with a comment chunk so that a different `$label` is a different file. */
    private function png(string $label = 'eye'): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('tEXt', "Comment\0" . $label)
            . $chunk('IDAT', (string) gzcompress("\0\xff\xff\xff")) . $chunk('IEND', '');
    }

    /** A JPEG with the right start and end and a comment segment: what the command reads is its first bytes, so it is not a picture to look at. */
    private function jpeg(string $label = 'eye'): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xFE" . pack('n', 2 + strlen($label)) . $label . "\xFF\xD9";
    }

    /** A WebP container (RIFF, size, WEBP, one chunk) in the same way: right start, not a picture to look at. */
    private function webp(string $label = 'eye'): string
    {
        $payload = "\x2f" . $label . (strlen($label) % 2 === 0 ? "\0" : '');
        $chunk = 'VP8L' . pack('V', strlen($payload)) . $payload;

        return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
    }

    /** The same file made `$size` bytes long (nothing after the end of a picture or the declared length of a model is read). */
    private function padded(string $bytes, int $size): string
    {
        return $bytes . str_repeat("\0", $size - strlen($bytes));
    }

    /** What an operator runs for a picture: licence and credit given, anything else as asked. */
    private function addImage(string $bytes, string $extension = '', array $options = ['--tenant' => 6]): int
    {
        return $this->add($bytes, $options + ['--licence' => 'CC0', '--credit' => 'Photo by A. Author'], $extension);
    }

    public function test_the_picture_fixtures_are_distinct_and_the_png_is_a_real_one(): void
    {
        $size = getimagesizefromstring($this->png());
        $this->assertSame([1, 1, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
        $this->assertNotSame($this->png('a'), $this->png('b'));
        $this->assertNotSame($this->jpeg('a'), $this->jpeg('b'));
        $this->assertNotSame($this->webp('a'), $this->webp('b'));
    }

    public function test_assettype_reads_the_first_bytes_and_nothing_else(): void
    {
        $store = new SimulationStore();
        $glb = $store->assetType($this->glb());
        $this->assertSame(['model', 'model/gltf-binary', 'glb', 25 * 1048576], [$glb['kind'], $glb['mime'], $glb['ext'], $glb['max']]);
        $png = $store->assetType($this->png());
        $this->assertSame(['image', 'image/png', 'png', 2 * 1048576], [$png['kind'], $png['mime'], $png['ext'], $png['max']]);
        $jpeg = $store->assetType($this->jpeg());
        $this->assertSame(['image', 'image/jpeg', 'jpg', ['jpg', 'jpeg']], [$jpeg['kind'], $jpeg['mime'], $jpeg['ext'], $jpeg['exts']]);
        $webp = $store->assetType($this->webp());
        $this->assertSame(['image', 'image/webp', 'webp'], [$webp['kind'], $webp['mime'], $webp['ext']]);

        $others = ['', 'glTF', 'GIF89a......', 'GIF87a......', '<svg xmlns="http://www.w3.org/2000/svg"/>', '<!doctype html><html></html>', 'RIFF....WAVEfmt ', 'RIFF....AVI LIST',
            'BM' . str_repeat("\0", 20), "%PDF-1.7\n%%EOF", "PK\x03\x04" . str_repeat("\0", 20)];
        foreach ($others as $head) {
            $this->assertNull($store->assetType($head), json_encode($head));
        }
        $this->assertNull($store->assetType(substr($this->png(), 0, 11)), 'fewer than 12 bytes is not enough to say');
        $this->assertSame('image/png', $store->assetType(substr($this->png(), 0, 12))['mime'], 'twelve is');
    }

    public function test_the_command_takes_a_png_a_jpeg_and_a_webp_and_stores_each_privately_under_its_own_extension(): void
    {
        $puts = $this->recordPuts();
        $cases = [
            'png' => [$this->png(), 'image/png', 'png', 'png'],
            'jpeg' => [$this->jpeg(), 'image/jpeg', 'jpg', 'JPEG'],   // named .JPEG, stored as .jpg
            'webp' => [$this->webp(), 'image/webp', 'webp', ''],      // no extension at all: the bytes decide
        ];
        $n = 0;
        foreach ($cases as $type => [$bytes, $mime, $stored, $named]) {
            $sha = hash('sha256', $bytes);
            $code = $this->addImage($bytes, $named, ['--tenant' => 6, '--name' => 'Retina photo ' . $type, '--source' => 'https://example.org/' . $type]);
            $out = Artisan::output();
            $this->assertSame(0, $code, $type . ': ' . $out);
            $path = "sim_assets/6/{$sha}.{$stored}";

            $this->assertSame($bytes, Storage::disk('digitalocean')->get($path), "{$type}: the exact bytes, under its own extension");
            $this->assertSame([$path, ['visibility' => 'private', 'mimetype' => $mime]], [$puts[$n][0], $puts[$n][2]], "{$type}: private, with its mime type");
            $n++;
            $row = DB::table('g2g_simulation_asset')->where('sha256', $sha)->first();
            $this->assertSame([6, 'image', $mime, strlen($bytes), $path, 'CC0', 'Photo by A. Author', 'https://example.org/' . $type],
                [(int) $row->sub_institute_id, $row->kind, $row->mime, (int) $row->bytes, $row->storage_path, $row->licence, $row->credit, $row->source_url], $type);

            $this->assertStringContainsString("stored: digitalocean:{$path} (private)", $out);
            $this->assertStringContainsString('{"id": "retina-photo-' . $type . '", "sha256": "' . $sha . '", "name": "Retina photo ' . $type . '", "kind": "image"}', $out, "{$type}: the snippet says it is an image");
            preg_match('/^\s*(\{"id": .*\})\s*$/m', $out, $m);
            $this->assertSame([$sha], (new SimulationStore())->parse(json_encode($this->specObject('eye-lab', [json_decode($m[1], true)])))['facts']['assets'], "{$type}: and a specification accepts it as printed");
        }
        $this->assertSame(3, DB::table('g2g_simulation_asset')->count());
    }

    public function test_a_file_named_for_one_kind_that_is_another_is_refused(): void
    {
        $files = ['a glb' => $this->glb(), 'a png' => $this->png(), 'a jpeg' => $this->jpeg(), 'a webp' => $this->webp()];
        $extensions = ['a glb' => ['glb'], 'a png' => ['png'], 'a jpeg' => ['jpg', 'jpeg'], 'a webp' => ['webp']];
        $refused = 0;
        foreach ($files as $what => $bytes) {
            foreach (['glb', 'png', 'jpg', 'jpeg', 'webp', 'PNG', 'Jpg'] as $extension) {
                $right = in_array(strtolower($extension), $extensions[$what], true);
                $code = $this->addImage($bytes, $extension, ['--tenant' => 6]);
                if ($right) {
                    $this->assertSame(0, $code, "{$what} named .{$extension}: " . Artisan::output());
                } else {
                    $refused++;
                    $this->assertSame(1, $code, "{$what} named .{$extension}");
                    $this->assertStringContainsString('is named .' . strtolower($extension), strtolower(Artisan::output()));
                }
            }
        }
        $this->assertSame(4 * 7 - 7, $refused, 'every wrong pairing, and no right one');
        $this->assertSame(4, DB::table('g2g_simulation_asset')->count(), 'only the four files named rightly were stored, once each');

        // the two the owner will meet
        $this->assertSame(1, $this->addImage($this->glb('renamed'), 'png'), 'a model renamed .png');
        $this->assertSame(1, $this->addImage($this->png('renamed'), 'glb'), 'a picture renamed .glb');
        $this->assertSame(4, DB::table('g2g_simulation_asset')->count());

        // a name that says nothing about the kind (.bin, .tmp, none) is no reason to refuse a file whose bytes are good
        $this->assertSame(0, $this->addImage($this->png('odd name'), 'bin'));
        $this->assertSame('image/png', DB::table('g2g_simulation_asset')->where('sha256', hash('sha256', $this->png('odd name')))->value('mime'));
    }

    public function test_svg_gif_html_and_the_like_are_refused_whatever_they_are_called(): void
    {
        $bad = [
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
            'svg with a prolog' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>',
            'gif89a' => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF;",
            'gif87a' => "GIF87a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF;",
            'html' => '<!doctype html><html><body><script>alert(1)</script></body></html>',
            'php' => '<?php echo 1; ?>' . str_repeat(' ', 20),
            'a wav (RIFF, not WEBP)' => 'RIFF' . pack('V', 40) . 'WAVEfmt ' . str_repeat("\0", 32),
            'a bmp' => 'BM' . str_repeat("\0", 30),
            'a pdf' => "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF",
            'plain text' => 'this is not a picture, whatever its name says',
        ];
        foreach ($bad as $what => $bytes) {
            foreach (['', 'png', 'jpg', 'jpeg', 'webp', 'glb', 'svg', 'gif', 'html'] as $extension) {
                $this->assertSame(1, $this->addImage($bytes, $extension), "{$what} named .{$extension}");
                $this->assertStringContainsString('first bytes', Artisan::output());
            }
        }
        $this->assertSame(1, $this->addImage(substr($this->png(), 0, 11), 'png'), 'a PNG cut off in its signature');
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles(), 'nothing was uploaded');
    }

    public function test_an_image_is_held_to_2_mb_while_a_model_keeps_its_25(): void
    {
        $limit = 2 * 1048576;
        foreach (['png' => $this->png(), 'jpg' => $this->jpeg(), 'webp' => $this->webp()] as $extension => $bytes) {
            $this->assertSame(1, $this->addImage($this->padded($bytes, $limit + 1), $extension), "{$extension} one byte over");
            $this->assertStringContainsString('the limit for an image is 2 MB', Artisan::output());
            $this->assertSame(0, $this->addImage($this->padded($bytes, $limit), $extension), "{$extension} exactly 2 MB: " . Artisan::output());
        }
        $this->assertSame(3, DB::table('g2g_simulation_asset')->count());

        // 3 MB is far over for a picture and nothing for a model
        $three = 3 * 1048576;
        $this->assertSame(1, $this->addImage($this->padded($this->png('three'), $three), 'png'));
        $model = 'glTF' . pack('V', 2) . pack('V', $three) . str_repeat("\0", $three - 12);
        $this->assertSame(0, $this->addImage($model, 'glb'), Artisan::output());
        $this->assertSame('model', DB::table('g2g_simulation_asset')->where('sha256', hash('sha256', $model))->value('kind'));
    }

    public function test_an_image_without_its_licence_or_credit_is_refused_like_a_model(): void
    {
        $png = $this->png();
        $this->assertSame(2, $this->add($png, ['--tenant' => 6, '--credit' => 'x'], 'png'), 'no licence');
        $this->assertSame(2, $this->add($png, ['--tenant' => 6, '--licence' => 'CC0'], 'png'), 'no credit');
        $this->assertSame(2, $this->add($png, ['--tenant' => 6, '--licence' => 'CC0', '--credit' => ' '], 'png'), 'a blank credit');
        $this->assertSame(0, DB::table('g2g_simulation_asset')->count());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());
    }

    public function test_a_platform_image_has_no_owner_and_its_own_extension(): void
    {
        $bytes = $this->webp('platform');
        $sha = hash('sha256', $bytes);
        $this->assertSame(0, $this->addImage($bytes, 'webp', ['--platform' => true]));

        $row = DB::table('g2g_simulation_asset')->first();
        $this->assertSame([null, 'image', 'image/webp', "sim_assets/platform/{$sha}.webp"], [$row->sub_institute_id, $row->kind, $row->mime, $row->storage_path]);
        Storage::disk('digitalocean')->assertExists("sim_assets/platform/{$sha}.webp");

        $this->assertSame(0, $this->addImage($bytes, 'webp', ['--platform' => true]));
        $this->assertStringContainsString('Already registered as asset 1', Artisan::output());
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count());
    }

    public function test_an_image_that_has_gone_missing_is_put_back_at_its_own_path_and_a_dry_run_writes_nothing(): void
    {
        $puts = $this->recordPuts();
        $bytes = $this->png('again');
        $sha = hash('sha256', $bytes);
        $path = "sim_assets/6/{$sha}.png";

        $this->assertSame(0, $this->addImage($bytes, 'png', ['--tenant' => 6, '--dry-run' => true]));
        $out = Artisan::output();   // read once: reading it empties it
        $this->assertStringContainsString('"kind": "image"', $out);
        $this->assertStringContainsString($path, $out);
        $this->assertSame([0, 0], [DB::table('g2g_simulation_asset')->count(), count($puts)]);

        $this->assertSame(0, $this->addImage($bytes, 'png'));
        Storage::disk('digitalocean')->delete($path);
        $this->assertSame(0, $this->addImage($bytes, 'png'));
        $this->assertStringContainsString('uploaded again', Artisan::output());
        $this->assertSame($bytes, Storage::disk('digitalocean')->get($path));
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count());
    }

    public function test_an_entry_may_say_whether_it_is_a_model_an_image_or_an_hdr_sky(): void
    {
        $sha = str_repeat('e', 64);
        $entries = [$this->model($sha, 'a'), $this->model($sha, 'b') + ['kind' => 'model'], $this->model($sha, 'c') + ['kind' => 'image'], $this->model($sha, 'd') + ['kind' => 'hdr']];
        $parsed = (new SimulationStore())->parse(json_encode($this->specObject('eye-lab', $entries)));
        $this->assertSame([$sha], $parsed['facts']['assets'], 'an image or a sky is a file the specification needs like any other');
        $this->assertSame([null, 'model', 'image', 'hdr'], array_map(fn ($m) => $m->kind ?? null, json_decode($parsed['json'])->assets->models), 'and kind is kept as it was written');

        foreach (['video', 'Image', 'MODEL', '', 'texture', 5, true, null, ['image'], (object) []] as $n => $bad) {
            $spec = $this->specObject();
            $spec->assets = (object) ['models' => [(object) ($this->model($sha) + ['kind' => $bad])]];
            $this->assertStringContainsString('"assets.models.0.kind" must be one of: model, image, hdr.', $this->problemsOf($spec), 'case ' . $n);
        }

        $r = $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('eye-lab', [$this->model($sha) + ['kind' => 'sound']])], $this->h())->assertStatus(422);
        $this->assertStringContainsString('"assets.models.0.kind"', implode(' | ', $r->json('problems')));
    }

    public function test_a_list_of_40_models_and_images_is_the_most(): void
    {
        $entry = fn (int $n) => $this->model(hash('sha256', (string) $n), 'asset-' . $n) + ['kind' => $n % 2 === 0 ? 'image' : 'model'];
        $this->assertCount(40, (new SimulationStore())->parse(json_encode($this->specObject('eye-lab', array_map($entry, range(1, 40)))))['facts']['assets']);

        $spec = $this->specObject('eye-lab', array_map($entry, range(1, 41)));
        $this->assertStringContainsString('"assets.models" must be a list of up to 40 models or images.', $this->problemsOf($spec));
        $this->postJson('/api/lms/simulations', ['spec' => $spec], $this->h())->assertStatus(422);
        $this->assertSame(0, DB::table('g2g_simulation')->count());
    }

    public function test_the_endpoint_serves_an_image_with_its_own_mime_type_and_the_headers_that_make_it_only_a_picture(): void
    {
        $files = [
            'png' => [$this->png('served'), 'image/png'],
            'jpeg' => [$this->jpeg('served'), 'image/jpeg'],
            'webp' => [$this->webp('served'), 'image/webp'],
        ];
        $model = $this->glb('served');
        $shaModel = $this->register(self::T1, $model);
        $entries = [$this->model($shaModel, 'eye')];
        foreach ($files as $type => [$bytes]) {
            $entries[] = $this->model($this->register(self::T1, $bytes), 'photo-' . $type, 'Photo ' . $type) + ['kind' => 'image'];
        }
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, $entries);

        foreach ($files as $type => [$bytes, $mime]) {
            $sha = hash('sha256', $bytes);
            $r = $this->get($this->url($sim, $sha), $admin)->assertOk();
            $this->assertSame($bytes, $r->streamedContent(), "{$type}: the exact bytes");
            $this->assertSame($mime, $r->headers->get('Content-Type'), $type);
            $this->assertSame((string) strlen($bytes), $r->headers->get('Content-Length'), $type);
            $this->assertSame('"' . $sha . '"', $r->headers->get('ETag'), $type);
            $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'), $type);
            $this->assertSame("default-src 'none'; sandbox", $r->headers->get('Content-Security-Policy'), $type);
            $this->assertSame('inline; filename="asset"', $r->headers->get('Content-Disposition'), $type);
            $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
            $this->assertStringContainsString('max-age=86400', (string) $r->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('sim_assets', json_encode($r->headers->all()), 'no storage path in any header');

            $this->get($this->url($sim, $sha), $admin + ['If-None-Match' => '"' . $sha . '"'])->assertStatus(304);
        }

        // a model is served as before: its mime type and "nosniff", without the picture-only headers
        $r = $this->get($this->url($sim, $shaModel), $admin)->assertOk();
        $this->assertSame($model, $r->streamedContent());
        $this->assertSame('model/gltf-binary', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertNull($r->headers->get('Content-Security-Policy'));
        $this->assertNull($r->headers->get('Content-Disposition'));
    }

    public function test_an_images_rights_are_a_models_rights(): void
    {
        $png = $this->png('t1 only');
        $sha = $this->register(self::T1, $png);
        $t1 = $this->h(self::T1);
        $sim = $this->simulation($t1, [$this->model($sha, 'photo') + ['kind' => 'image']]);
        $t2 = $this->h(self::T2);

        $this->get($this->url($sim, $sha), $t2)->assertNotFound();                       // not their simulation
        $mine = $this->unchecked(self::T2, 'my-eye', [$this->model($sha, 'photo') + ['kind' => 'image']]);   // (saving refuses this one now)
        $this->get($this->url($mine, $sha), $t2)->assertNotFound();                       // not their image, even named in their own
        $this->assertSame([], $this->getJson("/api/lms/simulations/{$mine}", $t2)->json('data.assets'));
        $this->json('GET', $this->url($sim, $sha))->assertStatus(401);
        $this->assertSame($png, $this->get($this->url($sim, $sha), $t1)->assertOk()->streamedContent());

        // a platform image is served to anyone's simulation that names it
        $platform = $this->webp('platform');
        $shaPlatform = $this->register(null, $platform);
        $theirs = $this->simulation($t2, [$this->model($shaPlatform, 'photo') + ['kind' => 'image']], 'with-platform-image');
        $this->assertSame($platform, $this->get($this->url($theirs, $shaPlatform), $t2)->assertOk()->streamedContent());
    }

    public function test_show_credits_an_image_with_its_kind(): void
    {
        $model = $this->register(self::T1, $this->glb('credit'), ['--name' => 'Eye']);
        $image = $this->register(self::T1, $this->jpeg('credit'), ['--name' => 'Retina', '--credit' => 'Retina photo by B. Author', '--licence' => 'CC BY-SA 4.0']);
        $admin = $this->h(self::T1);
        $sim = $this->simulation($admin, [$this->model($model, 'eye'), $this->model($image, 'retina') + ['kind' => 'image']]);

        $assets = $this->getJson("/api/lms/simulations/{$sim}", $admin)->assertOk()->json('data.assets');
        $this->assertSame([$model, $image], array_column($assets, 'sha256'));
        $this->assertSame(['model', 'image'], array_column($assets, 'kind'));
        $this->assertSame(['Retina', 'CC BY-SA 4.0', 'Retina photo by B. Author', strlen($this->jpeg('credit'))], [$assets[1]['name'], $assets[1]['licence'], $assets[1]['credit'], $assets[1]['bytes']]);
        $this->assertSame(['sha256', 'kind', 'name', 'bytes', 'licence', 'credit', 'source_url'], array_keys($assets[1]));
        $this->assertStringNotContainsString('sim_assets', json_encode($assets));
    }

    // ── the table ────────────────────────────────────────────────────────────

    public function test_the_migration_creates_the_registry_once_and_its_down_drops_only_that_table(): void
    {
        $this->assertSame(
            ['id', 'sub_institute_id', 'sha256', 'name', 'kind', 'mime', 'bytes', 'storage_path', 'licence', 'credit', 'source_url', 'created_by', 'created_at', 'updated_at', 'deleted_at'],
            Schema::getColumnListing('g2g_simulation_asset')
        );
        DB::table('g2g_simulation_asset')->insert(['sub_institute_id' => 6, 'sha256' => str_repeat('a', 64), 'name' => 'x', 'bytes' => 1, 'storage_path' => 'p']);
        $row = DB::table('g2g_simulation_asset')->first();
        $this->assertSame(['model', 'model/gltf-binary'], [$row->kind, $row->mime], 'the defaults');

        $migration = require base_path('database/migrations/2026_12_04_090000_create_g2g_simulation_asset_table.php');
        $migration->up();
        $this->assertSame(1, DB::table('g2g_simulation_asset')->count(), 'running it twice keeps the rows');

        try {
            DB::table('g2g_simulation_asset')->insert(['sub_institute_id' => 6, 'sha256' => str_repeat('a', 64), 'name' => 'twin', 'bytes' => 1, 'storage_path' => 'p']);
            $this->fail('the same file twice for one organisation should be refused by the unique index');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }

        $migration->down();
        $this->assertFalse(Schema::hasTable('g2g_simulation_asset'));
        $this->assertTrue(Schema::hasTable('g2g_simulation'), 'down() drops only its own table');
        $this->assertTrue(Schema::hasTable('g2g_simulation_version'));
    }
}
