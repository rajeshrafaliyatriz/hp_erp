<?php

namespace Tests\Feature;

use App\Services\Lms\SimulationAssetService;
use App\Services\Lms\SimulationLimits;
use App\Services\Lms\SimulationStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\SimulationTestWorld;
use Tests\TestCase;

/**
 * Simulation Learning, the author's upload: POST /api/lms/simulations/assets and GET /api/lms/simulations/limits. These pin who may upload,
 * that a file is told apart by its bytes, that a model is inspected before anything is stored, that the same file twice is one asset, that an
 * organisation has a quota and a person a throttle, that every refusal has the controller's { message, problems[] } shape with the right
 * status, and that the limits the screen is given are the ones this server enforces.
 */
class LmsSimulationUploadTest extends TestCase
{
    use SimulationTestWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSimulationWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownSimulationWorld();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A multipart upload of `$bytes` as `$name`; the details are given unless `$fields` says otherwise (null leaves one out). */
    private function upload(array $headers, string $bytes, string $name = 'eye.glb', array $fields = []): TestResponse
    {
        $fields = array_filter($fields + ['licence' => 'CC BY 4.0', 'credit' => 'Eye model by A. Author', 'source' => 'https://example.org/eye', 'attested' => '1'], fn ($v) => $v !== null);

        return $this->post('/api/lms/simulations/assets', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $fields, $headers);
    }

    private function rows(): int
    {
        return DB::table('g2g_simulation_asset')->count();
    }

    private function nothingStored(): void
    {
        $this->assertSame(0, $this->rows());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());
    }

    // ── who ──────────────────────────────────────────────────────────────────

    public function test_both_routes_need_a_token_and_the_right_to_add_or_edit(): void
    {
        $this->json('POST', '/api/lms/simulations/assets')->assertStatus(401);
        $this->json('GET', '/api/lms/simulations/limits')->assertStatus(401);

        $this->upload($this->h(self::T1, self::EMPLOYEE), $this->glb())->assertStatus(403);   // no right on the page
        $this->getJson('/api/lms/simulations/limits', $this->h(self::T1, self::EMPLOYEE))->assertStatus(403);
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $this->upload($this->h(self::T1, self::EMPLOYEE), $this->glb())->assertStatus(403);   // a learner may play, not author
        $this->getJson('/api/lms/simulations/limits', $this->h(self::T1, self::EMPLOYEE))->assertStatus(403);
        $this->allow(3, ['can_view' => 1, 'can_delete' => 1]);
        $this->upload($this->h(self::T1, 3), $this->glb())->assertStatus(403);                // deleting is not authoring either
        $this->nothingStored();

        // whoever may add, or edit, may upload and read the limits (a profile has one row of rights, so each case has its own profile)
        $this->allow(4, ['can_view' => 1, 'can_edit' => 1]);
        $this->upload($this->h(self::T1, 4), $this->glb('by editor'))->assertStatus(201);
        $this->getJson('/api/lms/simulations/limits', $this->h(self::T1, 4))->assertOk();
        DB::table('tbluserprofilemaster')->insert(['id' => 5, 'name' => 'Executive', 'role_key' => 'executive']);
        $this->allow(5, ['can_view' => 1, 'can_add' => 1]);
        $this->upload($this->h(self::T1, 5), $this->glb('by adder'))->assertStatus(201);
        $this->getJson('/api/lms/simulations/limits', $this->h(self::T1, 5))->assertOk();
        $this->upload($this->h(), $this->glb('by admin'))->assertStatus(201);
        $this->assertSame(3, $this->rows());
    }

    // ── what is stored ───────────────────────────────────────────────────────

    public function test_an_upload_is_stored_privately_under_its_hash_and_registered_for_the_callers_organisation(): void
    {
        $bytes = $this->glb();
        $sha = hash('sha256', $bytes);
        $r = $this->upload($this->h(self::T2), $bytes, 'Human Eye v2.glb')->assertStatus(201);

        $this->assertSame(['sha256' => $sha, 'kind' => 'model', 'mime' => 'model/gltf-binary', 'bytes' => strlen($bytes), 'created' => true], array_intersect_key($r->json('data'), array_flip(['sha256', 'kind', 'mime', 'bytes', 'created'])));
        $this->assertSame(['name' => 'Human Eye v2', 'licence' => 'CC BY 4.0', 'credit' => 'Eye model by A. Author', 'source_url' => 'https://example.org/eye'], array_intersect_key($r->json('data'), array_flip(['name', 'licence', 'credit', 'source_url'])));
        $this->assertStringNotContainsString('sim_assets', $r->getContent(), 'the storage path is never sent');

        Storage::disk('digitalocean')->assertExists("sim_assets/2/{$sha}.glb");   // the token's organisation, in its own folder
        $this->assertSame($bytes, Storage::disk('digitalocean')->get("sim_assets/2/{$sha}.glb"));
        $row = DB::table('g2g_simulation_asset')->first();
        $this->assertSame([2, $sha, 'model', 'model/gltf-binary', "sim_assets/2/{$sha}.glb"], [(int) $row->sub_institute_id, $row->sha256, $row->kind, $row->mime, $row->storage_path]);
        $this->assertNotNull($row->created_by);

        // a name that is given wins over the file's
        $named = $this->upload($this->h(), $this->glb('named'), 'x.glb', ['name' => 'The eye'])->assertStatus(201);
        $this->assertSame('The eye', $named->json('data.name'));

        // and the stored file really is a private object: the same call the command makes
        $this->assertTrue(Storage::disk('digitalocean')->exists("sim_assets/2/{$sha}.glb"));
    }

    public function test_the_organisation_and_owner_named_in_the_request_are_ignored(): void
    {
        $r = $this->upload($this->h(self::T1), $this->glb('mine'), 'eye.glb', ['sub_institute_id' => 2, 'platform' => 1, 'owner' => 'platform'])->assertStatus(201);
        $row = DB::table('g2g_simulation_asset')->first();
        $this->assertSame(1, (int) $row->sub_institute_id, 'the token decides; an upload is never a platform asset');
        Storage::disk('digitalocean')->assertExists("sim_assets/1/{$r->json('data.sha256')}.glb");
    }

    public function test_what_a_file_is_comes_from_its_bytes_not_its_name(): void
    {
        $admin = $this->h();
        $cases = ['model' => [$this->glb('k'), 'model.bin', 'model/gltf-binary'], 'image/png' => [$this->png(8, 8, 'k'), 'picture.bin', 'image/png'],
            'image/jpeg' => [$this->jpeg(), 'photo.dat', 'image/jpeg'], 'image/webp' => [$this->webp(), 'photo', 'image/webp']];
        foreach ($cases as $kind => [$bytes, $name, $mime]) {
            $r = $this->upload($admin, $bytes, $name)->assertStatus(201);
            $this->assertSame([str_starts_with($kind, 'image') ? 'image' : 'model', $mime], [$r->json('data.kind'), $r->json('data.mime')], $kind);
        }
        $this->assertSame(4, $this->rows());

        // a file called one thing that is another is refused, and so is everything that is not a model or one of three pictures
        $before = $this->rows();
        $this->assertStringContainsString('is named .glb but it is a PNG image', $this->upload($admin, $this->png(8, 8, 'z'), 'model.glb')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('is named .png', $this->upload($admin, $this->glb('z'), 'picture.png')->assertStatus(422)->json('message'));
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF;", '<!doctype html><script>1</script>', 'plain text, long enough to read', "PK\x03\x04" . str_repeat("\0", 30)] as $bad) {
            $this->assertStringContainsString('first bytes', $this->upload($admin, $bad, 'x.png')->assertStatus(422)->json('message'));
        }
        $this->assertSame($before, $this->rows());
    }

    // ── idempotence ──────────────────────────────────────────────────────────

    public function test_the_same_file_twice_is_one_asset_and_changes_nothing(): void
    {
        $bytes = $this->glb();
        $admin = $this->h();
        $first = $this->upload($admin, $bytes)->assertStatus(201);
        $again = $this->upload($admin, $bytes, 'renamed.glb', ['licence' => 'MIT', 'credit' => 'someone else'])->assertStatus(200);

        $this->assertTrue($first->json('data.created'));
        $this->assertFalse($again->json('data.created'));
        $this->assertSame($first->json('data.sha256'), $again->json('data.sha256'));
        $this->assertSame(1, $this->rows());
        $this->assertSame(['CC BY 4.0', 'Eye model by A. Author', 'eye'], [$again->json('data.licence'), $again->json('data.credit'), $again->json('data.name')], 'what is on record is what is returned, and is left as it was');

        // another organisation uploading the same file has an asset of its own
        $this->assertTrue($this->upload($this->h(self::T2), $bytes)->assertStatus(201)->json('data.created'));
        $this->assertSame(2, $this->rows());

        // the file goes back to the disk if it has gone missing, and the answer is still "nothing new"
        $path = "sim_assets/1/{$first->json('data.sha256')}.glb";
        Storage::disk('digitalocean')->delete($path);
        $this->assertFalse($this->upload($admin, $bytes)->assertStatus(200)->json('data.created'));
        Storage::disk('digitalocean')->assertExists($path);
    }

    public function test_an_asset_that_was_removed_is_registered_again_with_the_details_given_now(): void
    {
        $bytes = $this->glb();
        $admin = $this->h();
        $this->upload($admin, $bytes)->assertStatus(201);
        DB::table('g2g_simulation_asset')->update(['deleted_at' => now()]);

        $r = $this->upload($admin, $bytes, 'eye.glb', ['credit' => 'New credit'])->assertStatus(201);
        $this->assertTrue($r->json('data.created'));
        $this->assertSame(1, $this->rows(), 'not a second row: the unique index would refuse it');
        $this->assertSame(['New credit', null], [DB::table('g2g_simulation_asset')->value('credit'), DB::table('g2g_simulation_asset')->value('deleted_at')]);
    }

    // ── the details ──────────────────────────────────────────────────────────

    public function test_the_licence_and_credit_are_mandatory_and_every_problem_is_listed_in_the_controllers_shape(): void
    {
        $admin = $this->h();
        $r = $this->upload($admin, 'not a model', 'eye.glb', ['licence' => null, 'credit' => '  ', 'source' => 'http://example.org/x', 'name' => str_repeat('n', 121)])->assertStatus(422);
        $this->assertFalse($r->json('status'));
        $this->assertSame($r->json('problems'), $r->json('errors.file'), 'the same list the front end already reads');
        $problems = implode(' | ', $r->json('problems'));
        foreach (['"licence" is required', '"credit" is required', '"name" is 120 characters at most', '"source" must be an https address', 'first bytes'] as $needle) {
            $this->assertStringContainsString($needle, $problems);
        }
        $this->assertSame(implode(' ', $r->json('problems')), $r->json('message'));

        $long = $this->upload($admin, $this->glb(), 'eye.glb', ['licence' => str_repeat('l', 81), 'credit' => str_repeat('c', 301)])->assertStatus(422);
        $this->assertStringContainsString('"licence" is 80 characters at most', implode(' ', $long->json('problems')));
        $this->assertStringContainsString('"credit" is 300 characters at most', implode(' ', $long->json('problems')));

        $this->upload($admin, $this->glb('limits'), 'eye.glb', ['licence' => str_repeat('l', 80), 'credit' => str_repeat('c', 300), 'name' => str_repeat('n', 120)])->assertStatus(201);

        $array = $this->post('/api/lms/simulations/assets', ['file' => UploadedFile::fake()->createWithContent('a.glb', $this->glb('arr')), 'licence' => ['CC0'], 'credit' => 'x'], $admin)->assertStatus(422);
        $this->assertStringContainsString('"licence" must be text', $array->json('message'));
        $this->assertSame(1, $this->rows());
    }

    // ── size ─────────────────────────────────────────────────────────────────

    public function test_a_file_over_its_limit_is_a_413_and_a_missing_file_is_a_422(): void
    {
        $admin = $this->h();
        $model = SimulationStore::MAX_ASSET_BYTES + 1;
        $r = $this->upload($admin, 'glTF' . pack('V', 2) . pack('V', $model) . str_repeat("\0", $model - 12))->assertStatus(413);
        $this->assertStringContainsString('the limit for a model is 25 MB', $r->json('message'));
        $r = $this->upload($admin, $this->png(8, 8) . str_repeat("\0", SimulationStore::MAX_IMAGE_BYTES), 'p.png')->assertStatus(413);
        $this->assertStringContainsString('the limit for an image is 2 MB', $r->json('message'));
        $this->assertFalse($r->json('status'));
        $this->nothingStored();

        // a size problem is a 413 even with another problem beside it
        $this->upload($admin, $this->png(8, 8) . str_repeat("\0", SimulationStore::MAX_IMAGE_BYTES), 'p.png', ['licence' => null])->assertStatus(413);

        $this->post('/api/lms/simulations/assets', ['licence' => 'CC0', 'credit' => 'x'], $admin)->assertStatus(422)->assertJsonPath('problems.0', '"file" is required: send the 3D model or image as the multipart field "file".');

        // PHP itself refusing the file (upload_max_filesize) reaches the controller as a failed upload
        $php = new UploadedFile($this->tmpFile('x'), 'big.glb', 'model/gltf-binary', UPLOAD_ERR_INI_SIZE, true);
        $this->post('/api/lms/simulations/assets', ['file' => $php, 'licence' => 'CC0', 'credit' => 'x'], $admin)->assertStatus(413)->assertJsonPath('status', false);
        $partial = new UploadedFile($this->tmpFile('x'), 'big.glb', 'model/gltf-binary', UPLOAD_ERR_PARTIAL, true);
        $this->post('/api/lms/simulations/assets', ['file' => $partial, 'licence' => 'CC0', 'credit' => 'x'], $admin)->assertStatus(422);
        $this->nothingStored();
    }

    // ── contents ─────────────────────────────────────────────────────────────

    public function test_a_model_that_breaks_a_rule_is_refused_with_the_rule_and_the_file_named_and_nothing_is_stored(): void
    {
        $admin = $this->h();
        $triangles = ['accessors' => [['count' => 900003, 'componentType' => 5123, 'type' => 'SCALAR'], ['count' => 3, 'componentType' => 5126, 'type' => 'VEC3']], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 1], 'indices' => 0]]]]];
        $cases = [
            ['outside itself', $this->glb('a', ['buffers' => [['byteLength' => 4, 'uri' => 'https://example.org/x.bin']]])],
            ['"VENDOR_magic"', $this->glb('b', ['extensionsRequired' => ['VENDOR_magic']])],
            ['300,001 triangles', $this->glb('c', $triangles)],
            ['8192 x 8 pixels', $this->glb('d', ['images' => [['bufferView' => 0]], 'buffers' => [['byteLength' => 80]], 'bufferViews' => [['buffer' => 0, 'byteLength' => strlen($this->png(8192, 8))]]], $this->png(8192, 8))],
        ];
        foreach ($cases as [$needle, $bytes]) {
            $r = $this->upload($admin, $bytes, 'my model.glb')->assertStatus(422);
            $this->assertStringContainsString($needle, $r->json('message'));
            $this->assertStringContainsString('"my model.glb"', $r->json('message'), 'the file is named');
        }
        $this->nothingStored();

        // a picture on its own: too wide, or not readable as a picture (a JPEG with no frame header), is refused too
        $this->assertStringContainsString('4097 x 10 pixels; the limit for a picture is 4096 x 4096', $this->upload($admin, $this->png(4097, 10), 'wide.png')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('cannot be read as a picture', $this->upload($admin, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9", 'p.jpg')->assertStatus(422)->json('message'));
        $this->nothingStored();

        // and a model inside every rule is kept, pictures and an allowed extension included
        $fine = $this->glb('fine', ['extensionsRequired' => ['KHR_materials_unlit'], 'images' => [['bufferView' => 0]], 'buffers' => [['byteLength' => 80]], 'bufferViews' => [['buffer' => 0, 'byteLength' => strlen($this->png(64, 64))]]], $this->png(64, 64));
        $this->upload($admin, $fine, 'fine.glb')->assertStatus(201);
        $this->assertSame(1, $this->rows());
    }

    // ── quota ────────────────────────────────────────────────────────────────

    private function fill(?int $tenant, int $files, int $bytesEach = 1, bool $removed = false): void
    {
        $rows = [];
        for ($i = 0; $i < $files; $i++) {
            $rows[] = ['sub_institute_id' => $tenant, 'sha256' => hash('sha256', "filler {$tenant} {$i} {$removed}"), 'name' => 'filler', 'kind' => 'model', 'mime' => 'model/gltf-binary', 'bytes' => $bytesEach,
                'storage_path' => 'sim_assets/filler/' . $i, 'deleted_at' => $removed ? now() : null];
        }
        DB::table('g2g_simulation_asset')->insert($rows);
    }

    public function test_an_organisation_may_keep_200_files_and_only_its_own_live_ones_count(): void
    {
        $this->fill(self::T2, 150);                          // another organisation's
        $this->fill(null, 150);                              // the platform's
        $this->fill(self::T1, 60, 1, true);                  // removed ones
        $this->fill(self::T1, 199);
        $admin = $this->h();

        $kept = $this->glb('the 200th');
        $this->upload($admin, $kept)->assertStatus(201);     // the 200th fits
        $r = $this->upload($admin, $this->glb('the 201st'))->assertStatus(422);
        $this->assertStringContainsString('already keeps 200 uploaded files, which is the most it may hold (200)', $r->json('message'));
        $this->assertSame(200, DB::table('g2g_simulation_asset')->where('sub_institute_id', self::T1)->whereNull('deleted_at')->count());

        // the same file again costs nothing, so it is not refused; another organisation is unaffected
        $this->upload($admin, $kept)->assertStatus(200)->assertJsonPath('data.created', false);
        $this->upload($this->h(self::T2), $this->glb('theirs'))->assertStatus(201);
    }

    public function test_an_organisation_may_keep_500_mb_and_not_a_byte_more(): void
    {
        $first = $this->glb('fits exactly');
        $second = $this->glb('does not');
        $this->fill(self::T1, 1, SimulationAssetService::MAX_BYTES - strlen($first));
        $admin = $this->h();

        $this->upload($admin, $first)->assertStatus(201);    // exactly 500 MB in all
        $r = $this->upload($admin, $second)->assertStatus(422);
        $this->assertStringContainsString('would take your organisation\'s uploaded files to 500 MB; the most it may hold is 500 MB', $r->json('message'));
        $this->assertSame(2, $this->rows());
        $this->assertSame(['files' => 2, 'bytes' => SimulationAssetService::MAX_BYTES], app(SimulationAssetService::class)->usage(self::T1));
    }

    // ── failure and throttle ─────────────────────────────────────────────────

    public function test_a_disk_that_fails_is_a_503_that_says_nothing_about_the_disk_and_leaves_no_row(): void
    {
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->andThrow(new \RuntimeException('Bucket g2g-secret refused the key AKIA...'));
        Storage::set('digitalocean', $disk);

        $r = $this->upload($this->h(), $this->glb())->assertStatus(503);
        $this->assertFalse($r->json('status'));
        $this->assertStringNotContainsString('Bucket', $r->getContent());
        $this->assertStringNotContainsString('AKIA', $r->getContent());
        $this->assertStringContainsString('could not be saved just now', $r->json('message'));
        $this->assertSame(0, $this->rows(), 'a row that points at nothing is never written');
    }

    public function test_thirty_uploads_a_minute_for_each_person_and_not_for_each_address(): void
    {
        $busy = $this->h();
        $other = $this->h();   // same address, another person
        for ($i = 1; $i <= 30; $i++) {
            $this->post('/api/lms/simulations/assets', ['licence' => 'CC0', 'credit' => 'x'], $busy)->assertStatus(422);   // counted whatever the outcome
        }
        $r = $this->post('/api/lms/simulations/assets', ['licence' => 'CC0', 'credit' => 'x'], $busy)->assertStatus(429);
        $this->assertFalse($r->json('status'));
        $this->assertSame($r->json('problems'), $r->json('errors.file'));
        $this->assertNotNull($r->headers->get('Retry-After'));

        $this->upload($other, $this->glb('not limited'))->assertStatus(201);   // another person from the same address is not
        $this->assertSame(SimulationLimits::UPLOADS_PER_MINUTE, 30);
    }

    // ── limits as data ───────────────────────────────────────────────────────

    public function test_the_limits_are_data_and_show_what_this_organisation_uses(): void
    {
        $admin = $this->h();
        $r = $this->getJson('/api/lms/simulations/limits', $admin)->assertOk();
        $this->assertTrue($r->json('status'));
        $this->assertSame(['files' => 0, 'bytes' => 0], $r->json('data.use'));
        $this->assertSame(SimulationLimits::data(), array_diff_key($r->json('data'), ['use' => 1, 'server' => 1]));
        $this->assertArrayHasKey('uploadMaxBytes', $r->json('data.server'));

        $this->fill(self::T2, 5, 100);                       // not theirs
        $this->fill(null, 5, 100);                           // the platform's
        $this->fill(self::T1, 3, 1000, true);                // removed
        $bytes = $this->glb();
        $this->upload($admin, $bytes)->assertStatus(201);
        $this->fill(self::T1, 2, 500);
        $this->assertSame(['files' => 3, 'bytes' => strlen($bytes) + 1000], $this->getJson('/api/lms/simulations/limits', $admin)->json('data.use'));
        $this->assertSame(['files' => 0, 'bytes' => 0], $this->getJson('/api/lms/simulations/limits', $this->h(self::T2 + 1))->json('data.use'), 'an organisation with nothing');
    }

    public function test_the_limits_are_the_contracts_and_match_the_runtimes_export(): void
    {
        $data = SimulationLimits::data();

        // CONTRACT-v3 section 8, written out
        $this->assertSame(['bytes' => 1000000, 'jsonDepth' => 64, 'knobs' => 8, 'knobsDes' => 12, 'outputs' => 12, 'missions' => 6, 'assets' => 40, 'objects' => 48, 'steps' => 24, 'views' => 2, 'viewsDes' => 1,
            'bodies' => 24, 'joints' => 16, 'forces' => 16, 'worldEvents' => 8, 'physicsSteps' => 60000], $data['spec'], 'knobsDes is the one number the contract leaves implicit: 12 for a des engine, as in the runtime');
        $this->assertSame([26214400, 2097152, 300000, 4096, 134217728, 4096], [$data['asset']['modelBytes'], $data['asset']['imageBytes'], $data['asset']['triangles'], $data['asset']['textureSide'], $data['asset']['decodedBytes'], $data['asset']['imageSide']]);
        $this->assertSame(['files' => 200, 'bytes' => 500 * 1048576, 'uploadsPerMinute' => 30], $data['quota']);
        $this->assertSame(['g2g.sim/1', 'g2g.sim/2'], $data['schemas']);
        $this->assertSame(['formula', 'rigid', 'des'], $data['engines']);

        // and the mirror of the runtime's own export: when the runtime changes a number, this fails until the mirror is updated
        $mirror = json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/limits.json')), true);
        $this->assertSame($mirror, json_decode(json_encode($data), true));
    }

    public function test_a_size_from_php_ini_is_read_in_bytes(): void
    {
        foreach (['2M' => 2097152, '128K' => 131072, '1G' => 1073741824, '8388608' => 8388608, '8m' => 8388608, ' 2M ' => 2097152, '0' => 0, '-1' => -1, '' => 0, 'lots' => 0] as $ini => $bytes) {
            $this->assertSame($bytes, SimulationLimits::iniBytes($ini), json_encode($ini));
        }
        $this->assertTrue(SimulationLimits::serverUploadBytes() === null || SimulationLimits::serverUploadBytes() > 0);
    }

    public function test_an_upload_must_carry_the_attestation_that_the_person_may_use_the_file(): void
    {
        $admin = $this->h();
        $this->upload($admin, $this->glb('attest'), 'eye.glb', ['attested' => null])->assertStatus(422)->assertJsonPath('problems.0', 'Confirm that you may use this file under the licence you named: send "attested" as 1.');
        $this->upload($admin, $this->glb('attest'), 'eye.glb', ['attested' => '0'])->assertStatus(422);
        $this->upload($admin, $this->glb('attest'), 'eye.glb', ['attested' => '1'])->assertStatus(201);
    }
}
