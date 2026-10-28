<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Services\Lms\GlbInspector;
use App\Services\Lms\SimulationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\SimulationTestWorld;
use Tests\TestCase;

/**
 * Simulation Learning, the customer upload path, attacked by someone who did not write it. EVERY test asserts the SAFE behaviour, so a
 * failing test is a confirmed weakness; a passing one is something that held.
 */
class LmsSimulationAdversaryTest extends TestCase
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

    private function upload(array $headers, string $bytes, string $name = 'x.glb', array $fields = []): TestResponse
    {
        $fields = array_filter($fields + ['licence' => 'CC BY 4.0', 'credit' => 'By A. Author', 'attested' => '1'], fn ($v) => $v !== null);

        return $this->post('/api/lms/simulations/assets', ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $fields, $headers);
    }

    /** A binary glTF from raw parts, [type, data] each, padded to 4 bytes: lets a test write the parts a real exporter never would. */
    private function chunks(array $parts): string
    {
        $body = '';
        foreach ($parts as [$type, $data]) {
            $data .= str_repeat($type === 'JSON' ? ' ' : "\0", (4 - strlen($data) % 4) % 4);
            $body .= pack('V', strlen($data)) . $type . $data;
        }

        return 'glTF' . pack('V', 2) . pack('V', 12 + strlen($body)) . $body;
    }

    private function gltf(array $g = []): string
    {
        return json_encode($g + ['asset' => ['version' => '2.0']], JSON_UNESCAPED_SLASHES);
    }

    private function rows(): int
    {
        return DB::table('g2g_simulation_asset')->count();
    }

    private function v2(array $models, string $slug = 'adv-v2'): \stdClass
    {
        return json_decode(json_encode(['schema' => 'g2g.sim/2', 'id' => $slug, 'version' => 1, 'title' => 'V2', 'engine' => 'formula', 'meta' => ['minutes' => 10],
            'knobs' => [['id' => 'a']], 'model' => ['state' => [], 'outputs' => [['id' => 'o']]], 'views' => [['kind' => 'scene3d']],
            'flow' => ['steps' => [['type' => 'intro', 'id' => 'intro'], ['type' => 'mission', 'id' => 'm1']]], 'assets' => ['models' => $models]]));
    }

    // ── (b) what the player would execute or choke on ────────────────────────

    public function test_a_second_json_part_cannot_carry_what_the_check_never_saw(): void
    {
        // the player's loader (three.js GLTFLoader) keeps the LAST json part; the inspector reads the FIRST
        $evil = $this->gltf(['buffers' => [['byteLength' => 4, 'uri' => 'https://attacker.example/x.bin']]]);
        $glb = $this->chunks([['JSON', $this->gltf(['extras' => ['a' => 1]])], ['JSON', $evil]]);
        $r = $this->upload($this->h(), $glb);
        $this->assertSame(422, $r->getStatusCode(), 'a model whose effective description links outside the file was accepted (' . $r->getStatusCode() . ')');
        $this->assertSame(0, $this->rows());
    }

    public function test_a_second_bin_part_cannot_swap_the_picture_the_check_read(): void
    {
        $small = $this->png(8, 8);
        $bomb = $this->png(16384, 16384);   // same length, so the view fits either
        $json = $this->gltf(['buffers' => [['byteLength' => strlen($small)]], 'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => strlen($small)]], 'images' => [['bufferView' => 0, 'mimeType' => 'image/png']]]);
        $r = $this->upload($this->h(), $this->chunks([['JSON', $json], ["BIN\0", $small], ["BIN\0", $bomb]]));
        $this->assertSame(422, $r->getStatusCode(), 'a 16384 x 16384 picture hidden behind a second BIN part was accepted (' . $r->getStatusCode() . ')');
    }

    public function test_memory_reached_only_through_an_animation_or_a_skin_is_still_counted(): void
    {
        $big = ['count' => 100000000, 'componentType' => 5126, 'type' => 'VEC4'];   // 1.6 GB once opened, no data behind it
        $docs = [
            'animation' => $this->gltf(['accessors' => [$big], 'nodes' => [new \stdClass()], 'scenes' => [['nodes' => [0]]],
                'animations' => [['samplers' => [['input' => 0, 'output' => 0]], 'channels' => [['sampler' => 0, 'target' => ['node' => 0, 'path' => 'translation']]]]]]),
            'skin' => $this->gltf(['accessors' => [$big], 'nodes' => [['skin' => 0]], 'scenes' => [['nodes' => [0]]], 'skins' => [['inverseBindMatrices' => 0, 'joints' => [0]]]]),
        ];
        $accepted = [];
        foreach ($docs as $what => $json) {
            if ($this->upload($this->h(), $this->chunks([['JSON', $json]]))->getStatusCode() !== 422) {
                $accepted[] = $what;
            }
        }
        $this->assertSame([], $accepted, 'a model that allocates 1.6 GB when opened was accepted through: ' . implode(', ', $accepted));
    }

    public function test_odd_descriptions_never_crash_the_inspector(): void
    {
        $acc = ['count' => 3, 'componentType' => 5126, 'type' => 'VEC3'];
        $mesh = fn (array $p) => ['accessors' => [$acc], 'meshes' => [['primitives' => [$p]]], 'nodes' => [['mesh' => 0]], 'scenes' => [['nodes' => [0]]]];
        $docs = [
            'targets as an object' => $this->gltf($mesh(['attributes' => ['POSITION' => 0], 'targets' => ['a' => ['POSITION' => 0]]])),
            'attributes as a list' => $this->gltf($mesh(['attributes' => [0, 1]])),
            'children as an object' => $this->gltf(['nodes' => [['children' => ['x' => 1]], new \stdClass()], 'scenes' => [['nodes' => [0]]]]),
            'extensions as text' => $this->gltf(['accessors' => [$acc], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]], 'nodes' => [['mesh' => 0, 'extensions' => 'x']]]),
            'instancing as text' => $this->gltf(['accessors' => [$acc], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]], 'nodes' => [['mesh' => 0, 'extensions' => ['EXT_mesh_gpu_instancing' => ['attributes' => 'x']]]]]),
            'count of 1e999' => '{"asset":{"version":"2.0"},"accessors":[{"count":1e999,"componentType":5126,"type":"VEC3"}],"meshes":[{"primitives":[{"attributes":{"POSITION":0}}]}],"nodes":[{"mesh":0}]}',
            'scene index of 1e300' => '{"asset":{"version":"2.0"},"scene":1e300,"scenes":[{"nodes":[0]}],"nodes":[{}]}',
            'nesting of 70' => '{"asset":{"version":"2.0"},"extras":' . str_repeat('[', 70) . str_repeat(']', 70) . '}',
            'a view index as text' => $this->gltf(['images' => [['bufferView' => 'x']], 'bufferViews' => [['buffer' => 0, 'byteLength' => 4]], 'buffers' => [['byteLength' => 4]]]),
            'a buffer that is a number' => $this->gltf(['buffers' => [5], 'bufferViews' => [['buffer' => 0, 'byteLength' => 4]], 'images' => [['bufferView' => 0]]]),
            'asset as text' => '{"asset":"2.0"}',
            'a node that loops' => $this->gltf(['nodes' => [['children' => [0]]], 'scenes' => [['nodes' => [0]]], 'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]], 'accessors' => [$acc]]),
        ];
        $inspector = new GlbInspector();
        $crashed = [];
        foreach ($docs as $what => $json) {
            try {
                $inspector->inspectBytes($this->chunks([['JSON', $json]]), 'odd.glb');
            } catch (SimulationException) {
                // a refusal is an answer
            } catch (\Throwable $e) {
                $crashed[] = $what . ' => ' . get_class($e) . ': ' . $e->getMessage();
            }
        }
        $this->assertSame([], $crashed, "descriptions that crash the inspector (a 500 for the author):\n" . implode("\n", $crashed));
    }

    public function test_a_file_made_of_millions_of_empty_parts_cannot_burn_the_servers_cpu(): void
    {
        $json = $this->gltf();
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);
        $parts = pack('V', strlen($json)) . 'JSON' . $json . str_repeat(pack('V', 0) . 'ZZZZ', 2500000);   // 20 MB of 8-byte parts
        $glb = 'glTF' . pack('V', 2) . pack('V', 12 + strlen($parts)) . $parts;
        $this->assertLessThan(26214400, strlen($glb), 'the file must be one the 25 MB limit lets through');
        $path = $this->tmpFile($glb);
        $start = microtime(true);
        try {
            (new GlbInspector())->inspect($path, 'parts.glb');
        } catch (SimulationException) {
        }
        $took = microtime(true) - $start;
        $this->assertLessThan(1.5, $took, sprintf('inspecting a %.0f MB file of empty parts took %.1f s of one PHP worker', strlen($glb) / 1048576, $took));
    }

    public function test_thousands_of_pictures_pointing_at_one_big_view_cannot_burn_the_servers_cpu(): void
    {
        $bin = $this->png(8, 8);
        $bin .= str_repeat("\0", 1048576 - strlen($bin));
        $json = $this->gltf(['buffers' => [['byteLength' => 1048576]], 'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 1048576]], 'images' => array_fill(0, 40000, ['bufferView' => 0])]);
        $glb = $this->chunks([['JSON', $json], ["BIN\0", $bin]]);
        $start = microtime(true);
        try {
            (new GlbInspector())->inspectBytes($glb, 'views.glb');
        } catch (SimulationException) {
        }
        $took = microtime(true) - $start;
        $this->assertLessThan(1.5, $took, sprintf('40 000 pictures (a %d byte description) took %.1f s; a 4 MB description allows about 230 000', strlen($json), $took));
    }

    public function test_a_description_at_the_4_mb_limit_cannot_cost_hundreds_of_megabytes(): void
    {
        $json = '{"asset":{"version":"2.0"},"extras":[' . rtrim(str_repeat('{"a":1},', intdiv(GlbInspector::MAX_JSON_BYTES - 200, 8)), ',') . ']}';
        $this->assertLessThanOrEqual(GlbInspector::MAX_JSON_BYTES, strlen($json));
        $glb = $this->chunks([['JSON', $json]]);
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        try {
            (new GlbInspector())->inspectBytes($glb, 'dense.glb');
        } catch (SimulationException) {
        }
        $used = (memory_get_peak_usage() - $before) / 1048576;
        $this->assertLessThan(64, $used, sprintf('a %.1f MB description needed %.0f MB to inspect (a 128M memory_limit dies at 1 request)', strlen($json) / 1048576, $used));
    }

    public function test_truncated_and_lying_models_are_refused_never_a_500(): void
    {
        $ok = $this->glb('base');
        $json = $this->gltf();
        $cases = [
            'header longer than the file' => substr($ok, 0, 8) . pack('V', strlen($ok) + 100) . substr($ok, 12),
            'header shorter than the file' => substr($ok, 0, 8) . pack('V', strlen($ok) - 4) . substr($ok, 12),
            'cut in the middle of the json' => substr($ok, 0, 40),
            'a part of 4 GB' => 'glTF' . pack('V', 2) . pack('V', 24) . pack('V', 0xFFFFFFFF) . 'JSON' . 'abcd',
            'only the header' => 'glTF' . pack('V', 2) . pack('V', 12),
            'an empty description' => $this->chunks([['JSON', '']]),
            'a description that is a list' => $this->chunks([['JSON', '[]']]),
            'a description that is a number' => $this->chunks([['JSON', '5']]),
            'the data part first' => $this->chunks([["BIN\0", 'abcd'], ['JSON', $json]]),
            'bytes after the last part' => 'glTF' . pack('V', 2) . pack('V', strlen($ok) + 4) . substr($ok, 12) . 'abcd',
            'version 3' => 'glTF' . pack('V', 3) . substr($ok, 8),
            'a description nested 100 deep' => $this->chunks([['JSON', '{"asset":{"version":"2.0"},"x":' . str_repeat('[', 100) . str_repeat(']', 100) . '}']]),
            'a description of text' => $this->chunks([['JSON', "{'asset': 1}"]]),
        ];
        $admin = $this->h();
        $wrong = [];
        foreach ($cases as $what => $bytes) {
            $status = $this->upload($admin, $bytes)->getStatusCode();
            if (! in_array($status, [413, 422], true)) {
                $wrong[] = "{$what} => {$status}";
            }
        }
        $this->assertSame([], $wrong);
        $this->assertSame(0, $this->rows());
    }

    public function test_pictures_that_claim_huge_sizes_are_refused(): void
    {
        $cases = ['png 4097 x 1' => $this->png(4097, 1), 'png 1 x 4097' => $this->png(1, 4097), 'png 100000 x 100000' => $this->png(100000, 100000),
            'png 2^31-1' => $this->png(0x7fffffff, 0x7fffffff), 'jpeg 65535 x 65535' => $this->jpeg(65535, 65535), 'webp 4097 x 8' => $this->webp(4097, 8), 'webp 16384 x 16384' => $this->webp(16384, 16384)];
        $wrong = [];
        foreach ($cases as $what => $bytes) {
            $status = $this->upload($this->h(), $bytes, 'p' . (str_contains($what, 'png') ? '.png' : (str_contains($what, 'jpeg') ? '.jpg' : '.webp')))->getStatusCode();
            if ($status !== 422) {
                $wrong[] = "{$what} => {$status}";
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_files_that_are_not_a_model_or_a_picture_are_refused_whatever_they_are_called(): void
    {
        $pad = str_repeat(' ', 20);
        $contents = ['svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' . $pad, 'gif87' => "GIF87a\x01\x00\x01\x00\x80\x00\x00" . $pad, 'gif89' => "GIF89a\x01\x00\x01\x00\x80\x00\x00" . $pad,
            'html' => '<!DOCTYPE html><script>alert(1)</script>' . $pad, 'php' => '<?php system($_GET[0]); ?>' . $pad, 'sh' => "#!/bin/sh\nrm -rf /\n" . $pad, 'exe' => 'MZ' . str_repeat("\x90", 40),
            'pdf' => '%PDF-1.7 ' . $pad, 'zip' => "PK\x03\x04" . str_repeat("\0", 30), 'bmp' => 'BM' . str_repeat("\0", 30), 'tiff' => "II*\0" . str_repeat("\0", 30),
            'gltf json' => '{"asset":{"version":"2.0"}}' . $pad, 'riff but not webp' => 'RIFF' . pack('V', 20) . 'WAVEfmt ' . $pad, 'bom html' => "\xEF\xBB\xBF<html>" . $pad];
        $accepted = [];
        foreach ($contents as $what => $bytes) {
            foreach (['a.glb', 'a.png', 'a.jpg', 'a.webp', 'a.svg', 'a.html', 'a'] as $name) {
                $status = $this->upload($this->h(), $bytes, $name)->getStatusCode();
                if ($status !== 422) {
                    $accepted[] = "{$what} as {$name} => {$status}";
                }
            }
        }
        $this->assertSame([], $accepted);
        $this->assertSame(0, $this->rows());
    }

    // ── (c) quota, attestation, throttle; (e) the file name and the details ──

    public function test_the_attestation_cannot_be_dodged(): void
    {
        $admin = $this->h();
        $wrong = [];
        foreach (['missing' => null, 'zero' => '0', 'false' => 'false', 'no' => 'no', 'empty' => '', 'maybe' => 'maybe', 'off' => 'off', 'an array' => ['1'], 'a nested array' => ['a' => ['1']]] as $what => $v) {
            $status = $this->upload($admin, $this->glb('att ' . $what), 'a.glb', ['attested' => $v])->getStatusCode();
            if ($status !== 422) {
                $wrong[] = "{$what} => {$status}";
            }
        }
        $this->assertSame([], $wrong);
        $this->assertSame(0, $this->rows());
    }

    public function test_the_upload_throttle_follows_the_person_however_the_token_is_presented(): void
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'thr', 'sub_institute_id' => self::T1, 'user_profile_id' => self::ADMIN]);
        $user = tbluserModel::find($id);
        $a = $user->createToken('a')->plainTextToken;
        $b = $user->createToken('b')->plainTextToken;
        for ($i = 0; $i < 30; $i++) {
            $this->upload(['Authorization' => "Bearer {$a}", 'Accept' => 'application/json'], $this->glb("t{$i}"))->assertStatus(201);
        }
        $this->assertSame(429, $this->upload(['Authorization' => "Bearer {$b}", 'Accept' => 'application/json'], $this->glb('b1'))->getStatusCode(), 'a second token of the same person');
        $this->assertSame(429, $this->upload(['Accept' => 'application/json'], $this->glb('b2'), 'x.glb', ['token' => $a])->getStatusCode(), 'the token as a form field');
        $this->assertNotSame(201, $this->upload(['Authorization' => 'Bearer junk-' . uniqid(), 'Accept' => 'application/json'], $this->glb('b3'), 'x.glb', ['token' => $a])->getStatusCode(), 'a junk bearer beside the real token');
        $this->assertSame(30, $this->rows());

        // a flood of junk tokens from one address uses up that address, not other people's allowance
        for ($i = 0; $i < 35; $i++) {
            $this->upload(['Authorization' => 'Bearer junk-' . $i, 'Accept' => 'application/json'], $this->glb("j{$i}"));
        }
        $this->assertSame(201, $this->upload($this->h(self::T2), $this->glb('innocent'))->getStatusCode(), 'someone else, same address');
    }

    public function test_text_that_is_not_valid_utf8_is_refused_not_a_500_and_leaves_nothing_behind(): void
    {
        $r = $this->upload($this->h(), $this->glb('utf8'), 'u.glb', ['credit' => "Author \xC3\x28 broken"]);
        $this->assertSame(422, $r->getStatusCode(), 'status ' . $r->getStatusCode());
        $this->assertSame(0, $this->rows());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles());
    }

    public function test_when_the_registration_fails_after_the_file_is_stored_the_file_does_not_stay_behind(): void
    {
        // any failure between the put and the insert (a column that is too narrow, a character set, a deadlock, a lost connection)
        DB::statement("CREATE TRIGGER adv_boom BEFORE INSERT ON g2g_simulation_asset BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $r = $this->upload($this->h(), $this->glb('orphan'));
        $this->assertGreaterThanOrEqual(400, $r->getStatusCode());
        $this->assertSame(0, $this->rows());
        $this->assertSame([], Storage::disk('digitalocean')->allFiles(), 'an object the quota cannot see was left in the bucket');
    }

    public function test_a_hostile_file_name_and_details_reach_neither_the_path_nor_the_headers(): void
    {
        $admin = $this->h(self::T1);
        $png = $this->png(8, 8, '<script>alert(1)</script>') . '<html><script>alert(1)</script></html>';   // a picture with a web page glued on
        $img = $this->upload($admin, $png, "evil\r\nX-Injected: 1.png", ['name' => "n\r\nX-Injected: 2", 'credit' => "c\r\nX-Injected: 3"])->assertStatus(201)->json('data.sha256');
        $mdl = $this->upload($admin, $this->glb('m'), '../../../../etc/passwd.glb')->assertStatus(201)->json('data.sha256');
        $id = (int) $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('adv-hdr', [$this->model($img, 'pic') + ['kind' => 'image'], $this->model($mdl, 'm3d')])], $admin)->assertCreated()->json('data.id');

        foreach (Storage::disk('digitalocean')->allFiles() as $path) {
            $this->assertMatchesRegularExpression('#^sim_assets/1/[a-f0-9]{64}\.(png|glb)$#', $path);
        }
        $r = $this->get("/api/lms/simulations/{$id}/assets/{$img}", $admin)->assertOk();
        $this->assertFalse($r->headers->has('X-Injected'));
        $this->assertStringStartsWith('image/png', (string) $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
        $this->assertSame('inline; filename="asset"', $r->headers->get('Content-Disposition'));
        $m = $this->get("/api/lms/simulations/{$id}/assets/{$mdl}", $admin)->assertOk();
        $this->assertStringStartsWith('model/gltf-binary', (string) $m->headers->get('Content-Type'));
        $this->assertSame('nosniff', $m->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString('passwd', (string) $m->headers->get('Content-Disposition'));
    }

    public function test_refusals_never_name_the_servers_paths_or_database(): void
    {
        $admin = $this->h(self::T1);
        $theirs = $this->register(self::T2, $this->glb('theirs'));
        $bodies = [
            'truncated' => $this->upload($admin, substr($this->glb('x1'), 0, 40))->getContent(),
            'link outside' => $this->upload($admin, $this->chunks([['JSON', $this->gltf(['buffers' => [['byteLength' => 4, 'uri' => '../../../../etc/passwd']]])]]), '/etc/passwd.glb')->getContent(),
            'svg' => $this->upload($admin, '<svg xmlns="x"></svg>' . str_repeat(' ', 20), 'a.svg')->getContent(),
            'bad json' => $this->upload($admin, $this->chunks([['JSON', '{nope']]))->getContent(),
            'huge picture' => $this->upload($admin, $this->png(99999, 99999), 'a.png')->getContent(),
            'spec: bad json' => $this->call('POST', '/api/lms/simulations', [], [], [], $this->transformHeadersToServerVars($admin) + ['CONTENT_TYPE' => 'application/json'], '{"spec": {')->getContent(),
            'spec: other tenant' => $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('adv-leak', [$this->model($theirs)])], $admin)->getContent(),
        ];
        $needles = [base_path(), storage_path(), sys_get_temp_dir(), 'SQLSTATE', 'sim_assets', 'vendor/', 'Stack trace', 'g2g_simulation_asset', 'digitalocean'];
        $leaks = [];
        foreach ($bodies as $what => $body) {
            foreach ($needles as $needle) {
                if (str_contains($body, $needle) || str_contains($body, trim(json_encode($needle), '"'))) {
                    $leaks[] = "{$what} names {$needle}";
                }
            }
        }
        $this->assertSame([], $leaks);
        $this->assertStringNotContainsString($theirs, $bodies['spec: other tenant'], 'the other tenant hash is echoed only if the caller sent it; it must not add facts about it');
    }

    public function test_a_request_body_far_over_the_specification_limit_is_refused(): void
    {
        // What the application can guarantee: a body far over the limit is answered 413 and never reaches the controller's decoding or the database.
        // What it cannot: Laravel decodes a JSON body when it captures the request (Request::createFromBase), before any middleware runs, so the memory
        // of that one decode is spent whatever this app does. That is capped by the web server (see the deploy checklist: a body limit of about 1.2 MB for
        // POST and PUT on /api/lms/simulations and /api/lms/simulations/{id}) and by PHP's memory_limit, as for every JSON route of this application.
        $spec = json_encode($this->specObject('adv-big'));
        $body = '{"spec":' . $spec . ',"pad":[' . rtrim(str_repeat('{"a":1},', 300000), ',') . ']}';   // 2.4 MB of padding; the cap on the specification is 1 MB
        $server = $this->transformHeadersToServerVars($this->h()) + ['CONTENT_TYPE' => 'application/json'];
        $r = $this->call('POST', '/api/lms/simulations', [], [], [], $server, $body);
        $this->assertSame(413, $r->getStatusCode(), 'a body of ' . round(strlen($body) / 1048576, 1) . ' MB was answered ' . $r->getStatusCode());
        $this->assertSame(0, DB::table('g2g_simulation')->where('slug', 'adv-big')->count());
    }

    // ── (a), (d), (f) whose file, and which reference ────────────────────────

    public function test_a_reference_cannot_be_dressed_up_to_pass_for_a_registered_hash(): void
    {
        $admin = $this->h(self::T1);
        $own = $this->register(self::T1, $this->glb('own'));
        $theirs = $this->register(self::T2, $this->glb('theirs'));
        $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('adv-ok', [$this->model($own)])], $admin)->assertCreated();

        $variants = ['trailing newline' => $own . "\n", 'upper case' => strtoupper($own), 'another tenants' => $theirs, 'another tenants + newline' => $theirs . "\n", 'a trailing space' => $own . ' ',
            'a nul byte' => substr($own, 0, 63) . "\0", 'a full width digit' => '１' . substr($own, 1), 'a list' => [$own], 'a number' => 12345, 'a leading newline' => "\n" . $own];
        $accepted = [];
        $i = 0;
        foreach ($variants as $what => $sha) {
            $status = $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('adv' . $i++, [['id' => 'eye', 'sha256' => $sha, 'name' => 'Eye']])], $admin)->getStatusCode();
            if ($status !== 422) {
                $accepted[] = "{$what} => {$status}";
            }
        }
        $this->assertSame([], $accepted);
        $this->assertSame(1, DB::table('g2g_simulation')->count(), 'only the control was saved');
    }

    public function test_assets_that_are_not_a_list_of_objects_are_refused_in_every_shape(): void
    {
        $admin = $this->h(self::T1);
        $own = $this->register(self::T1, $this->glb('own'));
        $m = ['id' => 'eye', 'sha256' => $own];
        $shapes = ['assets as a list' => [$m], 'models as an object' => ['models' => ['eye' => $m]], 'models as text' => ['models' => $own], 'a model as text' => ['models' => [$own]], 'id as a number' => ['models' => [['id' => 5, 'sha256' => $own]]],
            'id missing' => ['models' => [['sha256' => $own]]], 'id with capitals' => ['models' => [['id' => 'Eye', 'sha256' => $own]]], 'sha256 as an object' => ['models' => [['id' => 'eye', 'sha256' => ['x' => $own]]]],
            'a kind that is a script' => ['models' => [$m + ['kind' => 'script']]], '41 models' => ['models' => array_map(fn ($n) => ['id' => 'm' . $n, 'sha256' => $own], range(1, 41))],
            'one id twice' => ['models' => [$m, $m]], 'assets as text' => $own, 'assets as a number' => 7];
        $accepted = [];
        $i = 0;
        foreach ($shapes as $what => $assets) {
            $spec = $this->specObject('adv' . $i++);
            $spec->assets = json_decode(json_encode($assets));
            $status = $this->postJson('/api/lms/simulations', ['spec' => $spec], $admin)->getStatusCode();
            if ($status !== 422) {
                $accepted[] = "{$what} => {$status}";
            }
        }
        $this->assertSame([], $accepted);
        $this->assertSame(0, DB::table('g2g_simulation')->count());
    }

    public function test_a_repeated_key_cannot_make_the_checked_document_differ_from_the_stored_one(): void
    {
        $admin = $this->h(self::T1);
        $own = $this->register(self::T1, $this->glb('own'));
        $theirs = $this->register(self::T2, $this->glb('theirs'));
        $server = $this->transformHeadersToServerVars($admin) + ['CONTENT_TYPE' => 'application/json'];
        $i = 0;
        foreach (['theirs then own' => [$theirs, $own], 'own then theirs' => [$own, $theirs]] as $what => [$first, $second]) {
            $json = json_encode($this->specObject('dup' . $i++, [$this->model($own)]));
            $raw = '{"spec":' . str_replace('"sha256":"' . $own . '"', '"sha256":"' . $first . '","sha256":"' . $second . '"', $json) . '}';
            $r = $this->call('POST', '/api/lms/simulations', [], [], [], $server, $raw);
            if ($r->getStatusCode() === 201) {
                $stored = (string) DB::table('g2g_simulation_version')->orderByDesc('id')->value('spec');
                $this->assertStringNotContainsString($theirs, $stored, "{$what}: saved a document that names another tenant's file");
            } else {
                $this->assertSame(422, $r->getStatusCode(), $what);
            }
        }
    }

    public function test_a_v2_specification_cannot_name_another_tenants_file_on_create_or_on_a_new_version(): void
    {
        $admin = $this->h(self::T1);
        $own = $this->register(self::T1, $this->glb('own'));
        $theirs = $this->register(self::T2, $this->glb('theirs'));
        $id = (int) $this->postJson('/api/lms/simulations', ['spec' => $this->v2([['id' => 'eye', 'sha256' => $own, 'kind' => 'model']])], $admin)->assertCreated()->json('data.id');
        $this->putJson("/api/lms/simulations/{$id}", ['spec' => $this->v2([['id' => 'eye', 'sha256' => $own], ['id' => 'lens', 'sha256' => $theirs]])], $admin)->assertStatus(422);
        $this->postJson('/api/lms/simulations', ['spec' => $this->v2([['id' => 'eye', 'sha256' => $theirs]], 'adv-v2b')], $admin)->assertStatus(422);
        $this->assertSame(1, (int) DB::table('g2g_simulation')->where('id', $id)->value('current_version'));
        $this->assertSame(1, DB::table('g2g_simulation')->count());
    }

    public function test_the_asset_route_serves_only_what_the_current_specification_names_to_those_who_may_see_it(): void
    {
        $admin = $this->h(self::T1);
        $a = $this->upload($admin, $this->glb('a'))->assertStatus(201)->json('data.sha256');
        $b = $this->upload($admin, $this->glb('b'))->assertStatus(201)->json('data.sha256');   // registered, never named
        $t2 = $this->upload($this->h(self::T2), $this->glb('t2'))->assertStatus(201)->json('data.sha256');
        $id = (int) $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('adv-asset', [$this->model($a)])], $admin)->assertCreated()->json('data.id');
        $get = fn (array $h, string $sha) => $this->get("/api/lms/simulations/{$id}/assets/{$sha}", $h)->getStatusCode();

        $this->assertSame(200, $get($admin, $a), 'control');
        $this->assertSame(404, $get($admin, $b), 'registered to the owner but not named by the specification');
        $this->assertSame(404, $get($admin, $t2), "another tenant's file");
        $this->assertSame(404, $get($this->h(self::T2), $a), "another tenant's simulation");
        foreach (['', '../../etc/passwd', strtoupper($a), substr($a, 0, 63), $a . '%0A', $a . 'a', '%2e%2e%2f' . substr($a, 9)] as $junk) {
            $this->assertSame(404, $get($admin, $junk), "route: {$junk}");
        }

        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $emp = $this->h(self::T1, self::EMPLOYEE);
        $this->assertSame(200, $get($emp, $a), 'a learner may fetch it while the simulation is published');
        foreach (['draft', 'archived'] as $status) {
            $this->putJson("/api/lms/simulations/{$id}", ['status' => $status], $admin)->assertOk();
            $this->assertSame(404, $get($emp, $a), "a learner, {$status}");
        }
        $this->putJson("/api/lms/simulations/{$id}", ['status' => 'published'], $admin)->assertOk();

        // a version saved before the check existed, naming another tenant's file: it is neither served nor credited
        DB::table('g2g_simulation_version')->insert(['simulation_id' => $id, 'version' => 2, 'created_at' => now(), 'sha256' => str_repeat('0', 64),
            'spec' => json_encode($this->specObject('adv-asset', [$this->model($a), $this->model($t2, 'other')]))]);
        DB::table('g2g_simulation')->where('id', $id)->update(['current_version' => 2]);
        $this->assertSame(404, $get($admin, $t2), 'a legacy specification naming another tenant');
        $this->assertSame([$a], array_column($this->getJson("/api/lms/simulations/{$id}", $admin)->json('data.assets'), 'sha256'));

        DB::table('g2g_simulation')->where('id', $id)->update(['deleted_at' => now()]);
        $this->assertSame(404, $get($admin, $a), 'a deleted simulation');
        $this->assertSame(404, $get($emp, $a), 'a deleted simulation, a learner');
    }
}
