<?php

namespace Tests\Feature;

use App\Services\Lms\SimulationException;
use App\Services\Lms\SimulationStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SimulationTestWorld;
use Tests\TestCase;

/**
 * Simulation Learning, g2g.sim/2 and reference integrity. A specification is now written in one of two schemas (v1 stays valid for ever, v2
 * names an engine, views and a flow of typed steps) and, either way, may name only files its owner has uploaded or the platform holds. These pin
 * the coarse checks the server makes of a v2 document (the runtime makes the deep ones), that the card's facts are the same for both, that v1
 * is exactly as it was, and that saving a file reference the owner cannot use is refused naming the file.
 */
class LmsSimulationV2Test extends TestCase
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

    /** The smallest g2g.sim/2 document the server's checks accept for an engine, with two mission steps; `$over` replaces whole keys (null removes one). */
    private function v2(string $engine = 'formula', array $over = []): \stdClass
    {
        $base = ['schema' => 'g2g.sim/2', 'id' => "v2-{$engine}", 'version' => 1, 'title' => "V2 {$engine}", 'engine' => $engine, 'meta' => ['minutes' => 10, 'category' => 'Science'],
            'knobs' => [['id' => 'a']], 'flow' => ['steps' => [['type' => 'intro', 'id' => 'intro'], ['type' => 'mission', 'id' => 'm1', 'title' => 'Hit the zone'], ['type' => 'mission', 'id' => 'm2']]]];
        $per = match ($engine) {
            'formula' => ['model' => ['state' => [], 'outputs' => [['id' => 'o']]], 'views' => [['kind' => 'scene3d'], ['kind' => 'trajectory']]],
            'rigid' => ['model' => ['outputs' => [['id' => 'o']]], 'world' => ['bodies' => [['id' => 'ball']]], 'views' => [['kind' => 'trajectory']]],
            default => ['model' => ['pools' => [['id' => 'p']]], 'scenarios' => [['id' => 's']], 'targets' => [['id' => 't']], 'views' => [['kind' => 'network']]],
        };

        return json_decode(json_encode(array_filter($over + $per + $base, fn ($v) => $v !== null)));
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

    // ── what a v2 document is ────────────────────────────────────────────────

    public function test_a_minimal_v2_specification_for_each_engine_is_accepted_and_the_card_reads_the_same_facts(): void
    {
        $store = new SimulationStore();
        foreach (['formula' => 'explorer', 'rigid' => 'explorer', 'des' => 'network'] as $engine => $family) {
            $parsed = $store->parse(json_encode($this->v2($engine)));
            $facts = $parsed['facts'];
            $this->assertSame(['g2g.sim/2', $engine, $family, "v2-{$engine}", 2, 10, 'Science'], [$facts['schema'], $facts['engine'], $facts['family'], $facts['slug'], $facts['missions'], $facts['minutes'], $facts['category']], $engine);
            $this->assertSame([], $facts['assets']);
            $this->assertSame('g2g.sim/2', json_decode($parsed['json'])->schema);
        }

        // the missions a card shows are the mission STEPS, whatever else the flow holds
        $more = $this->v2('formula', ['flow' => ['steps' => [['type' => 'intro', 'id' => 'i'], ['type' => 'play', 'id' => 'p'], ['type' => 'info', 'id' => 'n'], ['type' => 'mission', 'id' => 'm1'], ['type' => 'quiz', 'id' => 'q'], ['type' => 'predict', 'id' => 'pr'], ['type' => 'mission', 'id' => 'm2'], ['type' => 'explain', 'id' => 'e']]]]);
        $this->assertSame(2, $store->parse(json_encode($more))['facts']['missions']);

        // the shipped v1 documents read as they always did, and say what they are in the two-valued family
        $tiny = $store->parse((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')))['facts'];
        $lab = $store->parse((string) file_get_contents(base_path('tests/Fixtures/simulations/projectile-lab.json')))['facts'];
        $this->assertSame(['g2g.sim/1', 'des', 'network', 1], [$tiny['schema'], $tiny['engine'], $tiny['family'], $tiny['missions']]);
        $this->assertSame(['g2g.sim/1', 'formula', 'explorer', 5], [$lab['schema'], $lab['engine'], $lab['family'], $lab['missions']]);
    }

    public function test_the_things_a_v2_document_may_leave_out_or_carry(): void
    {
        $this->assertSame('', $this->problemsOf($this->v2('formula', ['views' => null])), 'no views: a document upgraded from a v1 with no scene');
        $this->assertSame('', $this->problemsOf($this->v2('des', ['views' => null])));
        $this->assertSame('', $this->problemsOf($this->v2('rigid', ['model' => ['state' => [], 'outputs' => [['id' => 'o']]]])), 'a rigid model that carries a state list is not wrong');
        $this->assertSame('', $this->problemsOf($this->v2('formula', ['views' => [['kind' => 'trajectory']]])));
        $this->assertSame('', $this->problemsOf($this->v2('formula', ['flow' => ['steps' => array_merge([['type' => 'mission', 'id' => 'm1']], array_map(fn ($i) => ['type' => 'info', 'id' => "i{$i}"], range(1, 23)))]])), 'exactly 24 steps');
    }

    public function test_a_v2_document_that_is_wrong_is_refused_naming_where(): void
    {
        $steps = fn (array $s) => ['flow' => ['steps' => $s]];
        $mission = fn (string $id) => ['type' => 'mission', 'id' => $id];
        $cases = [
            ['"schema" must be "g2g.sim/1" or "g2g.sim/2"', 'formula', ['schema' => 'g2g.sim/3']],
            ['"engine" must be one of: formula, rigid, des', 'formula', ['engine' => 'quantum']],
            ['"engine" must be one of: formula, rigid, des', 'formula', ['engine' => null]],
            ['"flow" is required', 'formula', ['flow' => null]],
            ['"flow" is required', 'formula', ['flow' => 'steps']],
            ['"flow.steps" must be a list of 1 to 24 items', 'formula', $steps([])],
            ['"flow.steps" must be a list of 1 to 24 items', 'formula', $steps(array_map(fn ($i) => ['type' => 'info', 'id' => "i{$i}"], range(1, 25)))],
            ['"flow.steps.1" must be an object', 'formula', $steps([$mission('m1'), 'x'])],
            ['"flow.steps.1.type" must be one of: intro, play, predict, mission, explain, info, quiz', 'formula', $steps([$mission('m1'), ['type' => 'video', 'id' => 'v']])],
            ['"flow.steps.1.type" must be one of', 'formula', $steps([$mission('m1'), ['id' => 'v']])],
            ['"flow.steps.0.id" is required', 'formula', $steps([['type' => 'mission']])],
            ['"flow.steps.0.id" is lowercase letters', 'formula', $steps([$mission('Bad Id')])],
            ['"flow.steps.0.id" must be text of 1 to 32 characters', 'formula', $steps([$mission(str_repeat('m', 33))])],
            ['"flow.steps.1.id" is "m1", which another step already uses', 'formula', $steps([$mission('m1'), $mission('m1')])],
            ['"flow.steps" needs at least one step of type "mission"', 'formula', $steps([['type' => 'intro', 'id' => 'i']])],
            ['"flow.steps" has 7 steps of type "mission"; the most is 6', 'formula', $steps(array_map(fn ($i) => $mission("m{$i}"), range(1, 7)))],
            ['"flow.steps.0.title" must be text of 1 to 120', 'formula', $steps([['type' => 'mission', 'id' => 'm', 'title' => 5]])],
            ['"flow.steps.0.brief" must be text of 1 to 1500', 'formula', $steps([['type' => 'mission', 'id' => 'm', 'brief' => str_repeat('b', 1501)]])],
            ['"views" must be a list of 0 to 2 items', 'formula', ['views' => [['kind' => 'scene3d'], ['kind' => 'trajectory'], ['kind' => 'network']]]],
            ['"views" must be a list of 0 to 2 items', 'formula', ['views' => 'scene3d']],
            ['"views" must be a list of 0 to 1 items', 'des', ['views' => [['kind' => 'network'], ['kind' => 'scene3d']]]],
            ['"views.0.kind" must be one of: scene3d, trajectory, network', 'formula', ['views' => [['kind' => '3d']]]],
            ['"views.0.kind" must be one of', 'formula', ['views' => [['title' => 'x']]]],
            ['"views.1.kind" is "scene3d", which another view already uses', 'formula', ['views' => [['kind' => 'scene3d'], ['kind' => 'scene3d']]]],
            ['"views.0" must be an object', 'formula', ['views' => ['scene3d']]],
            ['"world" is required for the "rigid" engine', 'rigid', ['world' => null]],
            ['"world" is required for the "rigid" engine', 'rigid', ['world' => []]],
            ['"world" belongs to the "rigid" engine only', 'formula', ['world' => ['bodies' => [['id' => 'b']]]]],
            ['"world" belongs to the "rigid" engine only', 'des', ['world' => ['bodies' => [['id' => 'b']]]]],
            ['"knobs" must be a list of 1 to 8 items', 'formula', ['knobs' => array_fill(0, 9, ['id' => 'k'])]],
            ['"knobs" must be a list of 1 to 8 items', 'rigid', ['knobs' => array_fill(0, 9, ['id' => 'k'])]],
            ['"knobs" must be a list of 1 to 12 items', 'des', ['knobs' => array_fill(0, 13, ['id' => 'k'])]],
            ['"knobs" must be a list of 1 to 8 items', 'formula', ['knobs' => null]],
            ['"model" is required', 'formula', ['model' => null]],
            ['"model.state" must be a list of 0 to 8 items', 'formula', ['model' => ['outputs' => [['id' => 'o']]]]],
            ['"model.outputs" must be a list of 1 to 12 items', 'formula', ['model' => ['state' => [], 'outputs' => []]]],
            ['"model.outputs" must be a list of 1 to 12 items', 'rigid', ['model' => ['outputs' => array_fill(0, 13, ['id' => 'o'])]]],
            ['"model.pools" must be a list of 1 to 10 items', 'des', ['model' => ['outputs' => [['id' => 'o']]]]],
            ['"scenarios" must be a list of 1 to 8 items', 'des', ['scenarios' => null]],
            ['"targets" must be a list of 1 to 6 items', 'des', ['targets' => null]],
            ['"assets.models.0.sha256" must be 64 lowercase', 'formula', ['assets' => ['models' => [['id' => 'eye', 'sha256' => 'nope']]]]],
            ['"assets.models" must be a list of up to 40', 'rigid', ['assets' => ['models' => array_map(fn ($i) => ['id' => "m{$i}", 'sha256' => hash('sha256', (string) $i)], range(1, 41))]]],
            ['"meta.minutes" must be a whole number from 1 to 120', 'des', ['meta' => ['minutes' => 500]]],
        ];
        foreach ($cases as $n => [$needle, $engine, $over]) {
            $this->assertStringContainsString($needle, $this->problemsOf($this->v2($engine, $over)), "case {$n}");
        }

        // an engine that is not known is the problem to fix first: no noise about what a particular engine is made of
        $noise = $this->problemsOf($this->v2('formula', ['engine' => 'quantum', 'model' => null, 'knobs' => null]));
        $this->assertStringContainsString('"engine"', $noise);
        $this->assertStringNotContainsString('"scenarios"', $noise);
        $this->assertStringNotContainsString('"model.pools"', $noise);
        $this->assertStringNotContainsString('"model.state"', $noise);
        $this->assertStringContainsString('"model" is required', $noise, 'but a model is wanted whatever the engine');

        // every problem is listed, in one sentence each
        $many = $this->problemsOf($this->v2('rigid', ['world' => null, 'views' => [['kind' => '3d']], 'flow' => ['steps' => [['type' => 'intro']]]]));
        foreach (['"world"', '"views.0.kind"', '"flow.steps.0.id"', 'at least one step of type "mission"'] as $needle) {
            $this->assertStringContainsString($needle, $many);
        }
    }

    public function test_v1_is_exactly_as_it_was(): void
    {
        $tiny = fn () => json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')));

        // a v1 that says "engine" and not "family" is still a v1 with no family; one with a "flow" and no "lesson" has no lesson
        $v1 = $tiny();
        unset($v1->family);
        $v1->engine = 'des';
        $this->assertStringContainsString('"family" must be one of: network, explorer.', $this->problemsOf($v1));
        $v1 = $tiny();
        unset($v1->lesson);
        $v1->flow = $this->v2()->flow;
        $this->assertStringContainsString('"lesson" is required', $this->problemsOf($v1));

        // the v1 caps are the v1 caps: 6 missions, 12 knobs for a network, 8 for an explorer
        $v1 = $tiny();
        $v1->lesson->missions = array_fill(0, 7, (object) ['id' => 'm']);
        $this->assertStringContainsString('"lesson.missions" must be a list of 1 to 6 items', $this->problemsOf($v1));
        $v1 = $tiny();
        $v1->knobs = array_fill(0, 13, (object) ['id' => 'k']);
        $this->assertStringContainsString('"knobs" must be a list of 1 to 12 items', $this->problemsOf($v1));
        $lab = json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/projectile-lab.json')));
        $lab->knobs = array_fill(0, 9, (object) ['id' => 'k']);
        $this->assertStringContainsString('"knobs" must be a list of 1 to 8 items', $this->problemsOf($lab));

        // and a nonsense schema still gets the old problems (an unknown family is held to the network's rules, as it always was)
        $nonsense = (object) ['schema' => 'nope', 'id' => 'Bad Id', 'title' => ''];
        foreach (['"schema" must be "g2g.sim/1" or "g2g.sim/2"', '"id"', '"title"', '"family"', '"knobs"', '"scenarios"', '"model"', '"lesson"'] as $needle) {
            $this->assertStringContainsString($needle, $this->problemsOf($nonsense), $needle);
        }
    }

    public function test_v2_is_saved_listed_and_versioned_through_the_api_and_a_v1_may_move_to_v2(): void
    {
        $admin = $this->h();
        foreach (['formula', 'rigid', 'des'] as $engine) {
            $card = $this->postJson('/api/lms/simulations', ['spec' => $this->v2($engine), 'status' => 'draft'], $admin)->assertCreated()->json('data');
            $this->assertSame([2, 'draft', 1], [$card['missions'], $card['status'], $card['version']], $engine);
            $shown = $this->getJson("/api/lms/simulations/{$card['id']}", $admin)->assertOk();
            $this->assertSame(['g2g.sim/2', $engine], [$shown->json('data.spec.schema'), $shown->json('data.spec.engine')]);
        }

        // a new version with a third mission changes the card's count
        $three = $this->v2('formula');
        $three->flow->steps[] = (object) ['type' => 'mission', 'id' => 'm3'];
        $three->title = 'V2 formula, three missions';
        $id = (int) DB::table('g2g_simulation')->where('slug', 'v2-formula')->value('id');
        $this->putJson("/api/lms/simulations/{$id}", ['spec' => $three], $admin)->assertOk()->assertJsonPath('data.missions', 3)->assertJsonPath('data.version', 2);

        // a simulation that began as a v1 may be saved as a v2 under the same id, and the v1 version is still there
        $this->postJson('/api/lms/simulations', ['spec' => json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')))], $admin)->assertCreated();
        $tiny = (int) DB::table('g2g_simulation')->where('slug', 'tiny-api')->value('id');
        $upgraded = $this->v2('des', ['id' => 'tiny-api', 'title' => 'Tiny API']);
        $this->putJson("/api/lms/simulations/{$tiny}", ['spec' => $upgraded], $admin)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.missions', 2);
        $this->assertSame('g2g.sim/1', $this->getJson("/api/lms/simulations/{$tiny}?version=1", $admin)->assertOk()->json('data.spec.schema'));
        $this->assertSame('g2g.sim/2', $this->getJson("/api/lms/simulations/{$tiny}", $admin)->json('data.spec.schema'));

        // a bad v2 is refused with the paths, and nothing is written
        $bad = $this->v2('formula', ['id' => 'bad-one', 'flow' => ['steps' => [['type' => 'intro', 'id' => 'i']]]]);
        $r = $this->postJson('/api/lms/simulations', ['spec' => $bad], $admin)->assertStatus(422);
        $this->assertStringContainsString('at least one step of type "mission"', implode(' ', $r->json('problems')));
        $this->assertSame(0, DB::table('g2g_simulation')->where('slug', 'bad-one')->count());
    }

    public function test_a_key_twice_in_one_object_and_nesting_too_deep_are_refused_before_anything_reads_the_document(): void
    {
        $store = new SimulationStore();
        $message = function (string $json) use ($store): string {
            try {
                $store->parse($json);
            } catch (SimulationException $e) {
                return $e->getMessage();
            }

            return '';
        };

        // the same key twice in one object: whatever the spacing, the escapes, or how deep
        foreach ([['{"schema":"g2g.sim/1","id":"a","id":"b"}', 'id'], ['{"a":{"b":1,"b":2}}', 'b'], ['{"x":1,"x":2}', 'x'], ['[{"k":1,"k":2}]', 'k'], ["{\"a\"\r\n :1,\"a\" : 2}", 'a'],
            ['{"a":1,"b":[1,{"c":{"d":1,"d":2}}]}', 'd']] as [$json, $key]) {
            $this->assertStringContainsString("the key \"{$key}\" twice in one object", $message($json), $json);
        }

        // and not where the same text is only a value, in another object, or inside a string
        foreach (['[{"a":1},{"a":2}]', '{"a":"a","b":"a"}', '{"a":{"a":1}}', '{"q\"":1,"q":2}', '{"a":1,"b":[{"a":3}],"c":{"a":4}}', '{"s":"\\\\","t":1}', <<<'JSON'
{"a":"x{\"b\":1,\"b\":2}","b":1}
JSON] as $json) {
            $this->assertStringNotContainsString('twice in one object', $message($json), $json);
        }

        // nesting: 64 deep is a document (not an object, so refused for that), 65 is too deep
        $this->assertStringContainsString('must be a JSON object', $message(str_repeat('[', 64) . str_repeat(']', 64)));
        $this->assertStringContainsString('nested deeper than 64 levels', $message(str_repeat('[', 65) . str_repeat(']', 65)));
        $this->assertStringContainsString('nested deeper than 64 levels', $message('{"a":' . str_repeat('[', 70) . str_repeat(']', 70) . '}'));
        $this->assertStringContainsString('not valid JSON', $message('{"a":'));

        // through the API the same, with the 422 and the controller's shape
        $r = $this->postJson('/api/lms/simulations', ['spec' => json_decode('{"schema":"g2g.sim/1"}')], $this->h())->assertStatus(422);
        $this->assertNotEmpty($r->json('problems'));
    }

    // ── reference integrity ──────────────────────────────────────────────────

    public function test_a_specification_may_name_only_files_its_owner_can_use(): void
    {
        $admin = $this->h(self::T1);
        $own = $this->register(self::T1, $this->glb('own'));
        $platform = $this->register(null, $this->glb('platform'));
        $theirs = $this->register(self::T2, $this->glb('theirs'));
        $gone = $this->register(self::T1, $this->glb('gone'));
        DB::table('g2g_simulation_asset')->where('sha256', $gone)->update(['deleted_at' => now()]);
        $never = hash('sha256', 'never uploaded');

        // its own, and the platform's, are fine - an image as well as a model, in a v1 and in a v2
        $ok = $this->specObject('with-files', [$this->model($own, 'eye'), $this->model($platform, 'lens')]);
        $this->postJson('/api/lms/simulations', ['spec' => $ok], $admin)->assertCreated();
        $v2 = $this->v2('formula', ['id' => 'v2-with-files', 'assets' => ['models' => [$this->model($own, 'eye') + ['kind' => 'model']]]]);
        $this->postJson('/api/lms/simulations', ['spec' => $v2], $admin)->assertCreated();

        // another organisation's, one that was removed, and one that was never uploaded are refused, each named by its id and place
        $bad = $this->specObject('bad-files', [$this->model($theirs, 'theirs'), $this->model($gone, 'gone'), $this->model($never, 'never'), $this->model($own, 'fine')]);
        $r = $this->postJson('/api/lms/simulations', ['spec' => $bad], $admin)->assertStatus(422);
        $this->assertCount(3, $r->json('problems'), 'one sentence for each file that cannot be used, none for the one that can');
        foreach (['"theirs" (assets.models.0)', '"gone" (assets.models.1)', '"never" (assets.models.2)'] as $needle) {
            $this->assertStringContainsString($needle, implode(' | ', $r->json('problems')));
        }
        $this->assertStringContainsString('has not been uploaded for this organisation', $r->json('problems.0'));
        $this->assertSame(0, DB::table('g2g_simulation')->where('slug', 'bad-files')->count());

        // the same document is fine for the organisation that owns the file
        $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('theirs-ok', [$this->model($theirs, 'theirs')])], $this->h(self::T2))->assertCreated();

        // a platform simulation can name platform files only
        $store = new SimulationStore();
        $store->create(null, null, json_encode($this->specObject('platform-ok', [$this->model($platform)])));
        try {
            $store->create(null, null, json_encode($this->specObject('platform-bad', [$this->model($own)])));
            $this->fail('a platform simulation naming an organisation\'s file should be refused');
        } catch (SimulationException $e) {
            $this->assertStringContainsString('"eye" (assets.models.0)', $e->getMessage());
        }
        $this->assertSame(0, DB::table('g2g_simulation')->where('slug', 'platform-bad')->count());
    }

    public function test_a_new_version_is_held_to_the_same_rule_and_an_unchanged_one_is_not_asked(): void
    {
        $admin = $this->h(self::T1);
        $a = $this->register(self::T1, $this->glb('a'));
        $b = $this->register(self::T1, $this->glb('b'));
        $id = (int) $this->postJson('/api/lms/simulations', ['spec' => $this->specObject('versions', [$this->model($a, 'a')])], $admin)->assertCreated()->json('data.id');

        // another file that exists: version 2
        $this->putJson("/api/lms/simulations/{$id}", ['spec' => $this->specObject('versions', [$this->model($a, 'a'), $this->model($b, 'b')])], $admin)->assertOk()->assertJsonPath('data.version', 2);

        // a file that does not: refused, and the current version is still 2
        $r = $this->putJson("/api/lms/simulations/{$id}", ['spec' => $this->specObject('versions', [$this->model($a, 'a'), $this->model(hash('sha256', 'nope'), 'ghost')])], $admin)->assertStatus(422);
        $this->assertStringContainsString('"ghost" (assets.models.1)', $r->json('message'));
        $this->assertSame([2, 2], [DB::table('g2g_simulation_version')->where('simulation_id', $id)->count(), (int) DB::table('g2g_simulation')->where('id', $id)->value('current_version')]);

        // saving exactly what is current changes nothing and asks nothing, even if a file has been removed since
        DB::table('g2g_simulation_asset')->update(['deleted_at' => now()]);
        $this->putJson("/api/lms/simulations/{$id}", ['spec' => $this->specObject('versions', [$this->model($a, 'a'), $this->model($b, 'b')])], $admin)->assertOk()->assertJsonPath('data.version', 2);
        // ... but a change to it is held to the rule again
        $this->putJson("/api/lms/simulations/{$id}", ['spec' => $this->specObject('versions', [$this->model($a, 'a')])], $admin)->assertStatus(422);

        // a card-only change never looks at the files
        $this->putJson("/api/lms/simulations/{$id}", ['status' => 'draft'], $admin)->assertOk();
    }

    public function test_the_importer_needs_its_files_registered_first(): void
    {
        $sha = hash('sha256', $this->glb('import'));
        $file = $this->tmpFile(json_encode($this->specObject('import-me', [$this->model($sha, 'eye')])), 'json');

        $this->assertSame(1, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6]));
        $this->assertStringContainsString('"eye" (assets.models.0)', Artisan::output());
        $this->assertSame(0, DB::table('g2g_simulation')->count());

        $this->assertSame($sha, $this->register(6, $this->glb('import')));
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6]), Artisan::output());
        $this->assertSame(1, DB::table('g2g_simulation')->count());

        // a platform import is held to platform files, and --update to the same rule
        $platformFile = $this->tmpFile(json_encode($this->specObject('import-platform', [$this->model($sha, 'eye')])), 'json');
        $this->assertSame(1, Artisan::call('simulations:import', ['file' => $platformFile, '--platform' => true]));
        $this->register(null, $this->glb('import'));
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $platformFile, '--platform' => true]));
        $changed = $this->tmpFile(json_encode($this->specObject('import-me', [$this->model($sha, 'eye'), $this->model(hash('sha256', 'x'), 'ghost')])), 'json');
        $this->assertSame(1, Artisan::call('simulations:import', ['file' => $changed, '--tenant' => 6, '--update' => true]));
    }
}
