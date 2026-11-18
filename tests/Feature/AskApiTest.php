<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsAskWorld;
use Tests\TestCase;

/**
 * The Ask chat's HTTP API (slice 8): the three routes, their rights and validation, the shape S8b reads, the conversations being their owner's alone, the
 * daily cap and the "unavailable" answer as an answer, and what Agent Run History does and does not show of a question. The pipeline behind the route is
 * tested in AskPipelineTest; the sweep of every agent route for the other organisation's ids is AgentGatewayTenantSweepTest.
 */
class AskApiTest extends TestCase
{
    use BuildsAskWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAskWorld();
    }

    /** @param array{id: int, h: array<string, string>} $user @param array<string, mixed> $body */
    private function askPost(array $user, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/agent/ask', $body, $user['h']);
    }

    // ---- who may use it ----

    public function test_every_route_needs_a_token_and_the_chats_own_right_and_a_person_who_may_only_read_run_history_cannot_use_it(): void
    {
        $this->postJson('/api/agent/ask', ['message' => 'Hi'])->assertStatus(401);
        $this->getJson('/api/agent/conversations')->assertStatus(401);
        $this->getJson('/api/agent/conversations/1')->assertStatus(401);
        $this->getJson('/api/agent/conversations', ['Authorization' => 'Bearer not-a-token'])->assertStatus(401);

        $noChat = $this->user([self::HISTORY_LINK => ['view', 'edit'], self::ORG_LINK => ['edit'], self::SIGNALS_RIGHT_LINK => ['view']]);   // no VIEW on any row of the door
        $editOnly = $this->user([self::ASK_LINK => ['edit']]);
        foreach ([$noChat, $editOnly] as $user) {
            $this->postJson('/api/agent/ask', ['message' => 'Hi'], $user['h'])->assertStatus(403);
            $this->getJson('/api/agent/conversations', $user['h'])->assertStatus(403);
            $this->getJson('/api/agent/conversations/1', $user['h'])->assertStatus(403);
        }
        $this->assertSame([], $this->model->calls);
        $this->assertSame(0, DB::table('g2g_agent_conversations')->count());

        $viewer = $this->user([self::ASK_LINK => ['view']]);
        $this->askModel(['calls' => []]);
        $this->postJson('/api/agent/ask', ['message' => 'Hi'], $viewer['h'])->assertOk();
    }

    public function test_the_door_is_the_old_chats_row_or_the_row_of_any_kind_of_record_ask_can_read(): void
    {
        $this->askModel(['calls' => []]);
        $doors = [
            self::ASK_LINK, self::LEADS_LINK, self::CONTACTS_LINK, self::CONTACTS_SUPPORT_LINK, self::ORG_LINK, self::ORG_LINK_SUPPORT, self::OPPS_LINK, self::PRODUCTS_LINK,
        ];
        foreach ($doors as $link) {
            $user = $this->user([$link => ['view']], self::T, 'Door', uniqid());
            $this->askPost($user, ['message' => 'Hi'])->assertOk();
            $this->getJson('/api/agent/conversations', $user['h'])->assertOk();
            $this->assertTrue(\App\Domain\Ask\AskRights::canAsk($this->callerOf($user)), $link);
        }
        // The door is exactly these rows: the old chat's and the record screens' (pinned, so widening or narrowing it is a decision, not an accident).
        $this->assertEqualsCanonicalizing($doors, explode('|', \App\Domain\Ask\AskRights::ASK_RIGHT));
        foreach (\App\Domain\Ask\AskRights::RECORD_LINKS as $links) {
            foreach ($links as $link) {
                $this->assertContains($link, $doors, "{$link} opens a kind of record, so it opens the door");
            }
        }
        // Run History, the Signals row and a rights row that is not VIEW do not open it.
        foreach ([[self::HISTORY_LINK => ['view']], [self::SIGNALS_RIGHT_LINK => ['view']], [self::LEADS_LINK => ['add', 'edit', 'delete']]] as $rights) {
            $shut = $this->user($rights, self::T, 'Shut', uniqid());
            $this->askPost($shut, ['message' => 'Hi'])->assertStatus(403);
            $this->assertFalse(\App\Domain\Ask\AskRights::canAsk($this->callerOf($shut)));
        }
    }

    // ---- validation ----

    public function test_a_question_must_be_text_of_one_to_a_thousand_characters_and_the_ids_and_context_must_be_well_formed(): void
    {
        $asker = $this->asker();
        $bad = [
            [[], 'message'], [['message' => ''], 'message'], [['message' => '   '], 'message'], [['message' => ['a']], 'message'], [['message' => str_repeat('x', 1001)], 'message'],
            [['message' => 'Hi', 'conversation_id' => 'abc'], 'conversation_id'], [['message' => 'Hi', 'conversation_id' => 0], 'conversation_id'], [['message' => 'Hi', 'conversation_id' => -1], 'conversation_id'],
            [['message' => 'Hi', 'context' => ['type' => 'user', 'id' => 1]], 'context.type'], [['message' => 'Hi', 'context' => ['type' => 'lead']], 'context.id'],
            [['message' => 'Hi', 'context' => ['id' => 3]], 'context.type'], [['message' => 'Hi', 'context' => ['type' => 'lead', 'id' => 'x']], 'context.id'],
            [['message' => 'Hi', 'context' => ['type' => 'lead', 'id' => 0]], 'context.id'], [['message' => 'Hi', 'context' => 'lead:3'], 'context'],
        ];
        foreach ($bad as [$body, $field]) {
            $res = $this->askPost($asker, $body)->assertStatus(422);
            $this->assertSame(0, $res->json('status'));
            $this->assertArrayHasKey($field, $res->json('errors'), json_encode($body));
        }
        $this->assertSame([], $this->model->calls);
        $this->assertSame(0, DB::table('g2g_agent_conversations')->count(), 'a refused request stores nothing');

        $this->askModel(['calls' => []]);
        $this->askPost($asker, ['message' => str_repeat('x', 1000)])->assertOk();
        $this->askPost($asker, ['message' => 'Hi', 'conversation_id' => null, 'context' => null])->assertOk();
        $this->askPost($asker, ['message' => 'Hi', 'context' => ['type' => 'opportunity', 'id' => 5]])->assertOk();
    }

    public function test_the_conversation_list_validates_its_paging(): void
    {
        $asker = $this->asker();
        $this->getJson('/api/agent/conversations?page=0', $asker['h'])->assertStatus(422);
        $this->getJson('/api/agent/conversations?page=abc', $asker['h'])->assertStatus(422);
        $this->getJson('/api/agent/conversations?per_page=0', $asker['h'])->assertStatus(422);
        $this->getJson('/api/agent/conversations/abc', $asker['h'])->assertStatus(404);
    }

    // ---- the answer's shape ----

    public function test_an_answer_has_the_shape_the_widget_reads(): void
    {
        $asker = $this->asker();
        $org = $this->makeOrg(self::T, 'Shape Org');
        $this->askModel($this->plan([['search_records', ['text' => 'Shape']]]), $this->says([['Shape Org is in your CRM.', ["organization:{$org['crm']}"]]]));

        $res = $this->askPost($asker, ['message' => 'Is Shape Org in the CRM?'])->assertOk();

        $this->assertSame(1, $res->json('status'));
        $data = $res->json('data');
        $this->assertSame(['conversation_id', 'message'], array_keys($data));
        $this->assertSame(['id', 'role', 'text', 'status', 'citations', 'created_at'], array_keys($data['message']));
        $this->assertSame('assistant', $data['message']['role']);
        $this->assertSame('answered', $data['message']['status']);
        $this->assertSame('Shape Org is in your CRM.', $data['message']['text']);
        $this->assertSame([['type' => 'organization', 'id' => (string) $org['crm'], 'label' => 'Shape Org', 'url' => "/module/crm/marketing/organizations/{$org['crm']}"]], $data['message']['citations']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['message']['created_at']);
        $this->assertIsInt($data['conversation_id']);
        $this->assertIsInt($data['message']['id']);
    }

    public function test_every_status_is_one_of_the_four_and_unavailable_is_an_answer_with_a_200_and_not_an_error(): void
    {
        $asker = $this->asker();
        $this->makeOrg(self::T, 'Status Org');

        $seen = [];
        $this->askModel(['calls' => []]);
        $seen[] = $this->askPost($asker, ['message' => 'no lookup'])->assertOk()->json('data.message.status');
        $this->askModel($this->plan([['search_records', ['text' => 'nothing here']]]));
        $seen[] = $this->askPost($asker, ['message' => 'nothing'])->assertOk()->json('data.message.status');
        $this->askModel($this->plan([['search_records', ['text' => 'Status']]]), $this->says([['Invented.', ['lead:1']]]));
        $seen[] = $this->askPost($asker, ['message' => 'refused'])->assertOk()->json('data.message.status');
        $this->model->unavailable = 'No AI model is set up for your organisation.';
        $res = $this->askPost($asker, ['message' => 'unavailable'])->assertOk();
        $seen[] = $res->json('data.message.status');

        $this->assertSame(['no_data', 'no_data', 'refused', 'unavailable'], $seen);
        $this->assertSame(1, $res->json('status'), 'an answer, not a crash');
        $this->assertIsInt($res->json('data.conversation_id'));
        $this->assertSame([], $res->json('data.message.citations'));
    }

    // ---- the person and the organisation come from the token ----

    public function test_the_person_and_organisation_are_the_tokens_whatever_the_request_names(): void
    {
        $asker = $this->asker();
        $other = $this->asker(self::T2, null, 'hr_manager', 'Else', 'Where');
        $this->askModel(['calls' => []]);

        $res = $this->askPost($asker, ['message' => 'Mine', 'sub_institute_id' => self::T2, 'user_id' => $other['id'], 'tenant' => self::T2])->assertOk();

        $conversation = DB::table('g2g_agent_conversations')->find($res->json('data.conversation_id'));
        $this->assertSame([self::T, $asker['id']], [(int) $conversation->sub_institute_id, (int) $conversation->user_id]);
        $run = DB::table('g2g_agent_runs')->first();
        $this->assertSame([self::T, $asker['id']], [(int) $run->sub_institute_id, (int) $run->user_id]);
        $this->assertSame(self::T, (int) DB::table('g2g_agent_messages')->value('sub_institute_id'));
        $this->assertSame(0, DB::table('g2g_agent_conversations')->where('user_id', $other['id'])->count());
    }

    // ---- the conversations are their owner's alone ----

    public function test_the_list_and_a_conversation_are_the_owners_and_another_persons_id_is_a_404_in_the_same_organisation_and_in_another(): void
    {
        $owner = $this->asker();
        $colleague = $this->asker(self::T, null, 'hr_manager', 'Col', 'League');
        $stranger = $this->asker(self::T2, null, 'hr_manager', 'Str', 'Anger');
        $org = $this->makeOrg(self::T, 'Owner Org');
        $this->askModel($this->plan([['search_records', ['text' => 'Owner']]]), $this->says([['Owner Org exists.', ["organization:{$org['crm']}"]]]));
        $mine = $this->askPost($owner, ['message' => 'First question'])->json('data.conversation_id');
        $this->askPost($owner, ['message' => 'Second question', 'conversation_id' => $mine]);
        $this->askModel(['calls' => []]);
        $theirs = $this->askPost($colleague, ['message' => 'Colleague question'])->json('data.conversation_id');
        $this->askPost($stranger, ['message' => 'Stranger question']);

        $list = $this->getJson('/api/agent/conversations', $owner['h'])->assertOk()->json('data');
        $this->assertSame([$mine], array_column($list['rows'], 'id'));
        $this->assertSame(['id', 'title', 'context', 'message_count', 'created_at', 'updated_at'], array_keys($list['rows'][0]));
        $this->assertSame(['First question', 4], [$list['rows'][0]['title'], $list['rows'][0]['message_count']]);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1], $list['pagination']);

        $one = $this->getJson("/api/agent/conversations/{$mine}", $owner['h'])->assertOk()->json('data');
        $this->assertSame(['user', 'assistant', 'user', 'assistant'], array_column($one['messages'], 'role'));
        $this->assertSame(['First question', 'Owner Org exists.', 'Second question', 'Owner Org exists.'], array_column($one['messages'], 'text'));
        $this->assertSame([null, 'answered', null, 'answered'], array_column($one['messages'], 'status'));
        $this->assertSame([], $one['messages'][0]['citations']);
        $this->assertSame('Owner Org', $one['messages'][1]['citations'][0]['label']);
        $this->assertSame(['id', 'role', 'text', 'status', 'citations', 'created_at'], array_keys($one['messages'][1]));
        $this->assertSame($mine, $one['id']);

        // another person's id, in the same organisation or in another, is the answer for an id that never existed
        $none = $this->getJson('/api/agent/conversations/987654', $owner['h'])->assertStatus(404);
        foreach ([$theirs, (int) DB::table('g2g_agent_conversations')->where('user_id', $stranger['id'])->value('id')] as $foreign) {
            $res = $this->getJson("/api/agent/conversations/{$foreign}", $owner['h'])->assertStatus(404);
            $this->assertSame($none->json(), $res->json(), 'the same body: a probe learns nothing');
            $this->assertStringNotContainsString('Colleague', $res->getContent());
        }
        $this->assertSame([], $this->getJson('/api/agent/conversations', $this->asker(self::T, null, 'hr_manager', 'Nobody', 'New')['h'])->json('data.rows'), 'and a person with none sees none');
    }

    public function test_a_row_of_another_organisation_that_points_at_this_person_or_conversation_shows_nothing_of_itself(): void
    {
        $asker = $this->asker();
        $this->askModel(['calls' => []]);
        $mine = $this->askPost($asker, ['message' => 'My question'])->json('data.conversation_id');
        // 1. another organisation's conversation that names THIS person as its owner (a bad import, a hand edit)
        $stray = (int) DB::table('g2g_agent_conversations')->insertGetId(['sub_institute_id' => self::T2, 'user_id' => $asker['id'], 'title' => 'A stray conversation', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('g2g_agent_messages')->insert(['sub_institute_id' => self::T2, 'conversation_id' => $stray, 'role' => 'user', 'content' => 'Stray content', 'created_at' => now()]);
        // 2. another organisation's message inside this person's own conversation
        DB::table('g2g_agent_messages')->insert(['sub_institute_id' => self::T2, 'conversation_id' => $mine, 'role' => 'assistant', 'content' => 'Secret of another organisation', 'status' => 'answered', 'created_at' => now()]);

        $list = $this->getJson('/api/agent/conversations', $asker['h'])->assertOk();
        $this->assertSame([$mine], array_column($list->json('data.rows'), 'id'));
        $this->assertSame(2, $list->json('data.rows.0.message_count'), 'only the two messages of this organisation are counted');
        $this->getJson("/api/agent/conversations/{$stray}", $asker['h'])->assertStatus(404);
        $this->askPost($asker, ['message' => 'Into the stray one', 'conversation_id' => $stray])->assertStatus(404);
        $one = $this->getJson("/api/agent/conversations/{$mine}", $asker['h'])->assertOk();
        $this->assertCount(2, $one->json('data.messages'));
        $this->assertSame(2, $one->json('data.message_count'), 'the count on the conversation is of this organisation\'s messages too');
        $this->assertStringNotContainsString('Secret of another', $one->getContent());
    }

    public function test_the_list_is_newest_first_and_pages(): void
    {
        $asker = $this->asker();
        $this->askModel(['calls' => []]);
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->askPost($asker, ['message' => "Question {$i}"])->json('data.conversation_id');
        }
        // an old conversation that was just asked into comes first
        $this->travel(2)->minutes();
        $this->askPost($asker, ['message' => 'Follow up', 'conversation_id' => $ids[0]]);

        $page1 = $this->getJson('/api/agent/conversations?per_page=2', $asker['h'])->json('data');
        $page3 = $this->getJson('/api/agent/conversations?per_page=2&page=3', $asker['h'])->json('data');

        $this->assertSame([$ids[0], $ids[4]], array_column($page1['rows'], 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 2, 'total' => 5, 'last_page' => 3], $page1['pagination']);
        $this->assertCount(1, $page3['rows']);
        $this->assertSame(50, $this->getJson('/api/agent/conversations?per_page=500', $asker['h'])->json('data.pagination.per_page'), 'at most 50 a page');
    }

    public function test_an_archived_conversation_is_not_listed_opened_or_asked_into(): void
    {
        $asker = $this->asker();
        $this->askModel(['calls' => []]);
        $id = $this->askPost($asker, ['message' => 'Old'])->json('data.conversation_id');
        DB::table('g2g_agent_conversations')->where('id', $id)->update(['archived_at' => now()]);

        $this->assertSame([], $this->getJson('/api/agent/conversations', $asker['h'])->json('data.rows'));
        $this->getJson("/api/agent/conversations/{$id}", $asker['h'])->assertStatus(404);
        $this->askPost($asker, ['message' => 'Again', 'conversation_id' => $id])->assertStatus(404);
    }

    public function test_asking_into_a_conversation_that_is_not_yours_is_a_404_with_the_same_body_and_stores_nothing(): void
    {
        $owner = $this->asker();
        $colleague = $this->asker(self::T, null, 'hr_manager', 'Col', 'League');
        $this->askModel(['calls' => []]);
        $id = $this->askPost($owner, ['message' => 'Mine'])->json('data.conversation_id');
        $before = [DB::table('g2g_agent_messages')->count(), DB::table('g2g_agent_runs')->count(), count($this->model->calls)];

        $foreign = $this->askPost($colleague, ['message' => 'Intruding', 'conversation_id' => $id])->assertStatus(404);
        $missing = $this->askPost($colleague, ['message' => 'Intruding', 'conversation_id' => 555555])->assertStatus(404);

        $this->assertSame($missing->json(), $foreign->json());
        $this->assertSame($before, [DB::table('g2g_agent_messages')->count(), DB::table('g2g_agent_runs')->count(), count($this->model->calls)]);
    }

    public function test_a_conversation_reads_its_newest_hundred_messages_in_order(): void
    {
        $asker = $this->asker();
        $this->askModel(['calls' => []]);
        $id = $this->askPost($asker, ['message' => 'Start'])->json('data.conversation_id');
        for ($i = 1; $i <= 110; $i++) {
            DB::table('g2g_agent_messages')->insert(['sub_institute_id' => self::T, 'conversation_id' => $id, 'role' => 'user', 'content' => "Bulk {$i}", 'created_at' => now()]);
        }

        $data = $this->getJson("/api/agent/conversations/{$id}", $asker['h'])->json('data');

        $this->assertCount(100, $data['messages']);
        $this->assertSame('Bulk 110', end($data['messages'])['text']);
        $this->assertSame(112, $data['message_count'], 'the count is of all of them');
        $ids = array_column($data['messages'], 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'oldest first');
    }

    // ---- the daily cap ----

    public function test_past_the_daily_cap_the_answer_is_a_429_with_its_code_and_the_limit(): void
    {
        config(['ask.daily_question_cap' => 2]);
        $asker = $this->asker();
        $this->askModel(['calls' => []]);
        $this->askPost($asker, ['message' => 'One'])->assertOk();
        $this->askPost($asker, ['message' => 'Two'])->assertOk();

        $res = $this->askPost($asker, ['message' => 'Three'])->assertStatus(429);

        $this->assertSame(['status' => 0, 'code' => 'daily_limit', 'message' => "You have used today's 2 questions. Try again tomorrow.", 'limit' => 2], $res->json());
        $this->assertSame(2, DB::table('g2g_agent_conversations')->count());
    }

    // ---- Agent Run History ----

    public function test_a_question_is_a_run_the_history_screen_does_not_list_unless_asked_and_it_never_shows_the_answer(): void
    {
        $asker = $this->asker();
        $admin = $this->user([self::HISTORY_LINK => ['view']]);   // can read run history, and nothing else
        $org = $this->makeOrg(self::T, 'History Org');
        $mineRun = (int) DB::table('g2g_agent_runs')->insertGetId([
            'sub_institute_id' => self::T, 'agent_key' => 'organisation', 'subject_type' => 'org_account', 'subject_id' => $org['identity'], 'trigger_source' => 'manual', 'status' => 'completed',
            'started_at' => now()->subMinute(), 'tool_calls' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->askModel($this->plan([['search_records', ['text' => 'History']]]), $this->says([['History Org holds the secret plan Zebra.', ["organization:{$org['crm']}"]]]));
        $conversation = $this->askPost($asker, ['message' => 'What is the secret plan of History Org?'])->json('data.conversation_id');
        $askRun = (int) DB::table('g2g_agent_runs')->where('agent_key', 'ask')->value('id');
        // An Ask run whose conversation id happens to equal an organisation identity's id must not be named after that organisation.
        DB::table('g2g_agent_runs')->where('id', $askRun)->update(['subject_id' => $org['identity']]);

        $default = $this->getJson('/api/agent/runs', $admin['h'])->assertOk();
        $this->assertSame([$mineRun], array_column($default->json('data'), 'id'), 'the history screen lists organisation runs, as it always did');
        $this->assertSame(1, $default->json('summary.total'));
        $this->assertSame(1, $default->json('meta.total'));

        $ask = $this->getJson('/api/agent/runs?agent=ask', $admin['h'])->assertOk();
        $this->assertSame([$askRun], array_column($ask->json('data'), 'id'));
        $row = $ask->json('data.0');
        $this->assertSame('ask', $row['agent_key']);
        $this->assertNull($row['subject']['name'], 'a run about a conversation is not named after an organisation that shares its number');
        $this->assertSame('conversation', $row['subject']['type']);
        $this->assertSame(['completed', 2400, 600], [$row['status'], $row['input_tokens'], $row['output_tokens']]);
        $this->assertSame('INR', $row['cost_currency']);
        $this->assertSame($asker['id'], $row['user_id']);

        $all = $this->getJson('/api/agent/runs?agent=all', $admin['h'])->assertOk();
        $this->assertEqualsCanonicalizing([$mineRun, $askRun], array_column($all->json('data'), 'id'));
        $this->assertSame(2, $all->json('summary.total'));
        $this->getJson('/api/agent/runs?agent=everyone', $admin['h'])->assertStatus(422);

        // The history reader sees that a question was asked and what it cost, never what was asked or answered.
        $detail = $this->getJson("/api/agent/runs/{$askRun}", $admin['h'])->assertOk();
        $this->assertNull($detail->json('data.brief'));
        $this->assertSame([], $detail->json('data.proposals'));
        $everything = $default->getContent() . $ask->getContent() . $all->getContent() . $detail->getContent();
        foreach (['Zebra', 'secret plan', 'What is the secret'] as $private) {
            $this->assertStringNotContainsString($private, $everything);
        }
        $this->assertSame(['model_call', 'search_records', 'model_call', 'answer'], array_column($detail->json('data.trace'), 'tool'));
        // ... and reading run history does not open the conversation.
        $this->getJson("/api/agent/conversations/{$conversation}", $admin['h'])->assertStatus(403);
    }

    public function test_another_organisations_ask_runs_are_not_in_this_ones_history(): void
    {
        $theirs = $this->asker(self::T2);
        $this->askModel(['calls' => []]);
        $this->askPost($theirs, ['message' => 'Their question']);
        $viewer = $this->user([self::HISTORY_LINK => ['view']]);

        $this->assertSame([], $this->getJson('/api/agent/runs?agent=all', $viewer['h'])->json('data'));
        $runId = (int) DB::table('g2g_agent_runs')->value('id');
        $this->getJson("/api/agent/runs/{$runId}", $viewer['h'])->assertStatus(404);
    }

    // ---- the context ----

    public function test_a_context_the_person_cannot_see_is_ignored_without_a_word_about_why(): void
    {
        $asker = $this->asker();
        $theirs = $this->makeOrg(self::T2, 'Elsewhere Org');
        $this->askModel(['calls' => []]);

        $res = $this->askPost($asker, ['message' => 'About this', 'context' => ['type' => 'organization', 'id' => $theirs['crm']]])->assertOk();

        $this->assertSame('no_data', $res->json('data.message.status'));
        $this->assertStringNotContainsString('Elsewhere', $res->getContent());
        $this->assertNull(DB::table('g2g_agent_conversations')->value('context_type'));
        $this->assertStringContainsString('nothing in particular', $this->plannerCall(0)[1]['content']);
    }
}
