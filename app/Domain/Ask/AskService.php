<?php

namespace App\Domain\Ask;

use App\Domain\AgentGateway\AgentAudit;
use App\Domain\AgentGateway\AgentModel;
use App\Domain\AgentGateway\AgentRefused;
use App\Domain\AgentGateway\AgentRunCost;
use App\Domain\AgentGateway\AutonomyPolicy;
use App\Domain\AI\Support\AiCompletion;
use App\Domain\AI\Support\AiCredentialsExhaustedException;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Ask\Tools\SearchRecordsTool;
use App\Domain\Signals\Products\ProductCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ask (slice 8 of the agentic CRM, Docs/agentic-crm/slices/S08-ask.md): a question in, a short answer with the records it rests on out. A BOUNDED pipeline,
 * not an open loop in which a model chooses tools, and READ ONLY from end to end: there is no tool that writes, so nothing a question says can make anything
 * happen. In order:
 *
 *   1. the conversation is the caller's (another person's or another organisation's id is "not found", the same answer);
 *   2. the per-person daily cap is not spent, and the question gets a run row (`g2g_agent_runs`, agent_key `ask`), in ONE transaction that locks the person's
 *      row, so a burst of questions cannot slip past the cap together;
 *   3. the agent's kill switch is off and a model is set up AND priced, or the answer is `unavailable` and the model is never called;
 *   4. MODEL CALL 1, the planner (AskPrompt::planner): which read-only lookups would settle the question. The server judges the plan (AskPlan): a lookup that is
 *      not one of the five, whose arguments do not validate, that repeats another or that is a fourth is dropped and audited;
 *   5. the lookups run with the CALLER's rights and organisation (the tools), each audited; what they return is the only thing the answer may rest on;
 *   6. nothing to look up, nothing found, or everything not permitted is answered by the server in fixed words (no second model call: cheaper, and there is
 *      nothing to make up);
 *   7. MODEL CALL 2, the answerer (AskPrompt::answerer), shown ONLY the rows that were retrieved; AskJudge keeps a statement only when it cites a row that was
 *      really shown, so a record the model made up cannot appear, and a claim that rested only on one is withheld;
 *   8. the answer and the question are stored in the caller's conversation and the run is closed with its tokens and cost in rupees, read back from the metering.
 *
 * Two model calls at most per question, both through AgentModel (the module the Organisation agent uses), both priced and filed under the run.
 */
final class AskService
{
    public const NOTHING_TO_LOOK_UP = 'I answer questions from your CRM records: leads, contacts, organisations, opportunities, signals and products. Ask about one of those, for example "what is open for Tapovan?" or "how many leads came from signals?".';

    public const NOTHING_FOUND = 'I have nothing on that.';

    public const REFUSED = 'I could not tie an answer to your records, so I am not giving one. Try naming a record, a product or a stage.';

    public function __construct(
        private readonly AgentModel $model,
        private readonly AutonomyPolicy $policy,
        private readonly AskToolRegistry $tools,
        private readonly AskConversations $conversations,
        private readonly AskPrompt $prompt,
        private readonly AskJudge $judge,
    ) {
    }

    /**
     * @param  array{type?: mixed, id?: mixed}|null  $context  the record the person is viewing, as the widget sent it (not trusted: looked up here)
     * @return array{conversation_id: int, message: array<string, mixed>}
     *
     * @throws AgentRefused `empty_question` 422, `not_found` 404 (a conversation that is not the caller's), `daily_limit` 429
     */
    public function ask(AskCaller $caller, string $message, ?int $conversationId, ?array $context = null): array
    {
        $question = AskText::line($message, (int) config('ask.max_message_chars', 1000));
        if ($question === '') {
            throw new AgentRefused('empty_question', 'Type a question.', 422);
        }
        if ($conversationId !== null && $this->conversations->find($caller, $conversationId) === null) {
            throw new AgentRefused('not_found', 'Conversation not found.', 404);
        }
        $viewing = $this->viewing($caller, $context);
        $correlation = (string) Str::uuid();

        // The cap, the conversation (when it is a new one) and the run are ONE transaction that holds a lock on the asker's own row, so two questions sent at
        // once cannot both find one place left. (The lock only holds on MariaDB; sqlite, which the tests run on, has none: the tests prove the cap, not the race.)
        [$conversation, $runId] = DB::transaction(function () use ($caller, $question, $conversationId, $viewing, $correlation): array {
            DB::table('tbluser')->where('id', $caller->userId)->where('sub_institute_id', $caller->tenantId)->lockForUpdate()->first();
            $cap = (int) config('ask.daily_question_cap', 30);
            $used = (int) DB::table('g2g_agent_runs')->where('sub_institute_id', $caller->tenantId)->where('agent_key', 'ask')->where('user_id', $caller->userId)
                ->where('started_at', '>=', now()->startOfDay())->where('status', '!=', 'unavailable')->count();
            if ($used >= $cap) {
                throw new AgentRefused('daily_limit', "You have used today's {$cap} questions. Try again tomorrow.", 429);
            }

            $now = now();
            $conversation = $conversationId ?? (int) DB::table('g2g_agent_conversations')->insertGetId([
                'sub_institute_id' => $caller->tenantId, 'user_id' => $caller->userId, 'title' => mb_substr($question, 0, (int) config('ask.title_chars', 150)),
                'context_type' => $viewing['type'] ?? null, 'context_id' => $viewing['id'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $run = (int) DB::table('g2g_agent_runs')->insertGetId([
                'sub_institute_id' => $caller->tenantId, 'user_id' => $caller->userId, 'agent_key' => 'ask', 'subject_type' => 'conversation', 'subject_id' => $conversation,
                'trigger_source' => 'chat', 'status' => 'running', 'started_at' => $now, 'correlation_id' => $correlation, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return [$conversation, $run];
        });

        $asked = (int) $this->store($caller, $conversation, 'user', $question, null, null, null)->id;
        $audit = new AgentAudit($caller->tenantId, $runId);
        $state = ['provider' => null, 'model' => null];

        try {
            $answer = $this->answer($caller, $runId, $conversation, $asked, $question, $viewing, $audit, $state);
            $runStatus = 'completed';
            $errorCode = null;
            $errorMessage = null;
        } catch (AskStopped $stopped) {
            $answer = ['status' => 'unavailable', 'text' => $stopped->getMessage(), 'citations' => []];
            [$runStatus, $errorCode, $errorMessage] = [$stopped->runStatus, $stopped->reason, $stopped->getMessage()];
        } catch (\Throwable $e) {
            Log::error('ask.failed', ['run' => $runId, 'tenant' => $caller->tenantId, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $answer = ['status' => 'unavailable', 'text' => 'I hit a problem and could not answer. Nothing was changed. Try again in a moment.', 'citations' => []];
            [$runStatus, $errorCode, $errorMessage] = ['failed', 'internal_error', 'The assistant hit a problem and stopped.'];
        }

        $reply = $this->store($caller, $conversation, 'assistant', $answer['text'], $answer['citations'], $answer['status'], $runId);
        DB::table('g2g_agent_conversations')->where('sub_institute_id', $caller->tenantId)->where('user_id', $caller->userId)->where('id', $conversation)->update(['updated_at' => now()]);
        $this->finish($caller->tenantId, $runId, $runStatus, $state, $audit, $errorCode, $errorMessage);

        return ['conversation_id' => $conversation, 'message' => $this->conversations->message($reply)];
    }

    /**
     * The pipeline from the switches to the answer.
     *
     * @param  array{type: string, id: int, label: string}|null  $viewing
     * @param  array{provider: ?string, model: ?string}  $state
     * @return array{status: string, text: string, citations: list<array<string, mixed>>}
     *
     * @throws AskStopped
     */
    private function answer(AskCaller $caller, int $runId, int $conversation, int $asked, string $question, ?array $viewing, AgentAudit $audit, array &$state): array
    {
        if ($this->policy->killed($caller->tenantId)) {
            throw new AskStopped('unavailable', 'agent_off', "I can't answer right now: the assistant has been switched off for your organisation by an administrator.");
        }
        if (($why = $this->model->readiness($caller->tenantId)) !== null) {
            throw new AskStopped('unavailable', 'model_unavailable', "I can't answer right now. {$why}");
        }

        $today = now()->toDateString();

        // ---- model call 1: the plan ----
        $products = array_map(fn (array $d) => (string) $d['label'], ProductCatalog::definitionsFor($caller->tenantId));
        $planned = $this->call($caller, $runId, $this->prompt->planner($this->tools, $question, $viewing, $this->history($caller, $conversation, $asked), $products, $today),
            (int) config('ask.planner_max_tokens', 400), 'plan', $audit, $state);
        $plan = AskPlan::parse($planned->text, $caller, $this->tools)
            ?? throw new AskStopped('failed', $planned->wasTruncated() ? 'output_truncated' : 'bad_model_output', 'I could not work out how to look that up. Try asking it another way.');
        foreach ($plan->dropped as $drop) {
            $audit->record($drop['name'], $drop['args'], 'denied', $drop['reason']);
        }
        if ($plan->calls === []) {
            $audit->record('answer', ['status' => 'no_data', 'why' => 'nothing_to_look_up'], 'denied', 'nothing_to_look_up');

            return ['status' => 'no_data', 'text' => self::NOTHING_TO_LOOK_UP, 'citations' => []];
        }

        // ---- the lookups, with the caller's rights and organisation ----
        $results = [];
        foreach ($plan->calls as $call) {
            $args = $call['args'];
            if (($args['from_call'] ?? null) !== null) {
                $earlier = $plan->indexOfPosition((int) $args['from_call']);
                $found = $earlier !== null && isset($results[$earlier])
                    ? collect($results[$earlier]->rows)->first(fn (AskRow $row) => $row->type === $args['type']) : null;
                $args['id'] = $found !== null ? (int) $found->id : null;
            }
            $started = microtime(true);
            $result = $call['tool']->run($caller, $args);
            $results[] = $result;
            $denied = $result->status === ToolResult::NOT_PERMITTED;
            $audit->record($call['tool']->name(), $args, $denied ? 'denied' : 'read', $denied ? 'not_permitted' : null, null, 'rows:' . count($result->rows), (int) round((microtime(true) - $started) * 1000));
        }

        $shown = $this->shown($results);
        $notes = $this->notes($results);
        if ($shown === []) {
            $allDenied = collect($results)->every(fn (ToolResult $r) => $r->status === ToolResult::NOT_PERMITTED);
            $status = $allDenied ? 'refused' : 'no_data';
            $audit->record('answer', ['status' => $status, 'rows' => 0], 'denied', $allDenied ? 'not_permitted' : 'no_rows');

            return ['status' => $status, 'text' => ($allDenied ? '' : self::NOTHING_FOUND . ' ') . implode(' ', $notes), 'citations' => []];
        }

        // ---- model call 2: the answer, from ONLY what was retrieved ----
        $answered = $this->call($caller, $runId, $this->prompt->answerer($question, array_values($shown), $results, $today), (int) config('ask.answer_max_tokens', 800), 'answer', $audit, $state);
        $verdict = $this->judge->judge($answered->text, $shown)
            ?? throw new AskStopped('failed', $answered->wasTruncated() ? 'output_truncated' : 'bad_model_output', 'I found records but could not write the answer. Try again.');
        $detail = ['returned' => $verdict->returned, 'kept' => count($verdict->statements), 'withheld' => $verdict->withheld, 'citations_removed' => $verdict->removedCitations, 'rows_shown' => count($shown)];

        if ($verdict->returned === 0) {
            $audit->record('answer', $detail + ['status' => 'no_data'], 'denied', 'model_found_nothing_to_say');

            return ['status' => 'no_data', 'text' => self::NOTHING_FOUND . ($notes !== [] ? ' ' . implode(' ', $notes) : ''), 'citations' => []];
        }
        if ($verdict->statements === []) {
            $audit->record('answer', $detail + ['status' => 'refused'], 'denied', 'no_valid_citation');

            return ['status' => 'refused', 'text' => self::REFUSED . ($notes !== [] ? ' ' . implode(' ', $notes) : ''), 'citations' => []];
        }
        $audit->record('answer', $detail + ['status' => 'answered'], 'applied');

        return [
            'status' => 'answered',
            'text' => implode("\n", array_column($verdict->statements, 'text')) . ($notes !== [] ? "\n\n" . implode(' ', $notes) : ''),
            'citations' => $verdict->citations(),
        ];
    }

    /**
     * One model call, audited. A model that is not set up, out of quota or out of credit, or one that fails, is an `unavailable` answer and never an exception
     * for the person: it ends the question through AskStopped.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{provider: ?string, model: ?string}  $state
     *
     * @throws AskStopped
     */
    private function call(AskCaller $caller, int $runId, array $messages, int $maxTokens, string $step, AgentAudit $audit, array &$state): AiCompletion
    {
        $started = microtime(true);
        try {
            $completion = $this->model->complete($caller->tenantId, $caller->userId, $messages, $runId, $maxTokens);
        } catch (AiNotConfiguredException|AiQuotaExceededException|AiCredentialsExhaustedException $e) {
            Log::warning('ask.model_unavailable', ['run' => $runId, 'tenant' => $caller->tenantId, 'step' => $step, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);

            throw new AskStopped('unavailable', 'model_unavailable', "I can't answer right now: the AI service is not available for your organisation (not set up, out of quota or out of credit). Try again later.");
        } catch (\Throwable $e) {
            Log::warning('ask.model_call_failed', ['run' => $runId, 'tenant' => $caller->tenantId, 'step' => $step, 'exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);

            throw new AskStopped('failed', 'model_error', 'I could not reach the AI service just now. Try again in a moment.');
        }
        $state = ['provider' => mb_substr($completion->provider, 0, 30), 'model' => $completion->model !== null ? mb_substr($completion->model, 0, 80) : null];
        $audit->record('model_call', ['module' => $this->model->module(), 'step' => $step, 'input_tokens' => $completion->inputTokens, 'output_tokens' => $completion->outputTokens, 'finish_reason' => $completion->finishReason],
            'read', null, null, null, (int) round((microtime(true) - $started) * 1000));

        return $completion;
    }

    /**
     * The rows the answerer is shown, by "type:id", each once, in the order the lookups returned them, as many as fit the character budget. A row that does
     * not fit is not shown, and so cannot be cited: the same rule as the Organisation agent's context.
     *
     * @param  list<ToolResult>  $results
     * @return array<string, AskRow>
     */
    private function shown(array $results): array
    {
        $budget = (int) config('ask.max_result_chars', 12000);
        $used = 0;
        $out = [];
        foreach ($results as $result) {
            foreach ($result->rows as $row) {
                if (isset($out[$row->refKey()])) {
                    continue;
                }
                $length = mb_strlen($row->line()) + 1;
                if ($out !== [] && $used + $length > $budget) {
                    continue;   // a smaller later row may still fit
                }
                $used += $length;
                $out[$row->refKey()] = $row;
            }
        }

        return $out;
    }

    /**
     * The sentences the SERVER adds, in fixed words: what was looked up and found nothing, and the kinds of record the person may not open. Never the model's
     * words: an absence the model asserted could not be checked, one the server saw can.
     *
     * @param  list<ToolResult>  $results
     * @return list<string>
     */
    private function notes(array $results): array
    {
        $notes = [];
        $kinds = [];
        foreach ($results as $result) {
            if ($result->status === ToolResult::OK && $result->rows === [] && $result->searched !== '') {
                $notes["Nothing found {$result->searched}."] = true;
            }
            foreach ($result->withheld as $kind) {
                $kinds[$kind] = true;
            }
        }
        $notes = array_keys($notes);
        if ($kinds !== []) {
            $names = array_keys($kinds);
            $joined = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : $names[0];
            $notes[] = "I can't look at {$joined} for you: your role does not have access to them.";
        }

        return $notes;
    }

    /**
     * The record the person is viewing, looked up HERE: the widget's word is not trusted. A record that is not the caller's organisation's, one that was
     * deleted and one the caller's profile may not open are all the same: no context, and nothing is said about why.
     *
     * @param  array{type?: mixed, id?: mixed}|null  $context
     * @return array{type: string, id: int, label: string}|null
     */
    private function viewing(AskCaller $caller, ?array $context): ?array
    {
        if ($context === null) {
            return null;
        }
        $type = SearchRecordsTool::kind($context['type'] ?? null);
        $id = $context['id'] ?? null;
        if ($type === null || ! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1 || ! AskRights::canRead($caller, $type)) {
            return null;
        }
        $record = AskRecords::find($caller, $type, (int) $id);

        return $record === null ? null : ['type' => $type, 'id' => (int) $id, 'label' => AskRecords::label($type, $record)];
    }

    /**
     * The last few messages of the conversation before this question, oldest first, for the planner to resolve "and for them?". They are data, like the question.
     *
     * @return list<array{role: string, text: string}>
     */
    private function history(AskCaller $caller, int $conversation, int $before): array
    {
        $rows = DB::table('g2g_agent_messages')->where('sub_institute_id', $caller->tenantId)->where('conversation_id', $conversation)->where('id', '<', $before)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'unavailable'))->orderByDesc('id')->limit((int) config('ask.history_messages', 4))->get(['role', 'content']);

        return $rows->reverse()->values()->map(fn ($m) => ['role' => (string) $m->role, 'text' => (string) AskText::clip($m->content, (int) config('ask.history_message_chars', 300))])->all();
    }

    /**
     * @param  list<array<string, mixed>>|null  $citations
     */
    private function store(AskCaller $caller, int $conversation, string $role, string $text, ?array $citations, ?string $status, ?int $runId): object
    {
        $id = (int) DB::table('g2g_agent_messages')->insertGetId([
            'sub_institute_id' => $caller->tenantId, 'conversation_id' => $conversation, 'role' => $role, 'content' => $text,
            'citations' => $citations !== null ? (string) json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'status' => $status, 'run_id' => $runId, 'created_at' => now(),
        ]);

        return DB::table('g2g_agent_messages')->where('sub_institute_id', $caller->tenantId)->where('id', $id)->first();
    }

    /**
     * Closes the run: its status, the tokens and cost read back from the metering rows (rupees, from the rate card; NULL when a call that used tokens has no
     * price: never a made-up 0), the number of audit rows, and why it stopped when it did.
     *
     * @param  array{provider: ?string, model: ?string}  $state
     */
    private function finish(int $tenantId, int $runId, string $status, array $state, AgentAudit $audit, ?string $errorCode, ?string $errorMessage): void
    {
        $cost = AgentRunCost::read($tenantId, $runId);
        DB::table('g2g_agent_runs')->where('sub_institute_id', $tenantId)->where('id', $runId)->update([
            'status' => $status, 'finished_at' => now(), 'provider' => $state['provider'], 'model' => $state['model'],
            'input_tokens' => $cost['input_tokens'], 'output_tokens' => $cost['output_tokens'], 'cost' => $cost['cost'], 'cost_currency' => $cost['currency'],
            'tool_calls' => min(65535, $audit->count()), 'error_code' => $errorCode, 'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null, 'updated_at' => now(),
        ]);
    }
}
