<?php

namespace App\Domain\AI\Conversation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and writes conversation transcripts.
 *
 * Replaces the in-memory `Map` in `packages/conversational-ai-core/src/history.ts`,
 * which lost every conversation on restart and was invisible to the server. See the
 * migration for the full argument; the short version is that an assistant answering
 * questions about an organisation's people must leave a record that AI Audit can read.
 *
 * TENANT SCOPE IS ON EVERY QUERY, NOT ON THE CALLER
 *
 * Every method takes `$institute` and filters by it. A conversation id alone is never
 * enough to read a transcript — `find()` and `turns()` both re-apply the filter, so a
 * caller who guesses an id gets nothing rather than somebody else's conversation.
 * That matters more here than anywhere else in this feature: a transcript is the one
 * record that may quote an organisation's data verbatim.
 */
final class ConversationStore
{
    /** How much of a transcript is replayed to the model. */
    public const CONTEXT_TURNS = 20;

    /**
     * Find an existing conversation for this session key, or start one.
     *
     * The session key comes from the client so a browser can resume a conversation it
     * began before the first turn was stored. It is scoped by organisation in the
     * unique index, because a client-supplied identifier is not something to trust
     * for global uniqueness.
     */
    public function resume(
        string $sessionKey,
        int|string|null $institute,
        ?int $userId,
        ?string $moduleKey = null,
        int|string|null $clientId = null
    ): object {
        $existing = DB::table('ai_conversations')
            ->where('session_key', $sessionKey)
            ->where('sub_institute_id', $institute)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = DB::table('ai_conversations')->insertGetId([
            'session_key' => $sessionKey,
            'title' => null,
            'module_key' => $moduleKey,
            'turn_count' => 0,
            'status' => 'active',
            'last_turn_at' => null,
            'user_id' => $userId,
            'sub_institute_id' => $institute,
            'client_id' => $clientId === '' ? null : $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('ai_conversations')->where('id', $id)->first();
    }

    /** One conversation, or null when it is not this organisation's. */
    public function find(int $id, int|string|null $institute): ?object
    {
        return DB::table('ai_conversations')
            ->where('id', $id)
            ->where('sub_institute_id', $institute)
            ->first();
    }

    /**
     * The transcript, oldest first.
     *
     * @return array<int, object>
     */
    public function turns(int $conversationId, int|string|null $institute, ?int $limit = null): array
    {
        $conversation = $this->find($conversationId, $institute);

        if ($conversation === null) {
            return [];
        }

        $query = DB::table('ai_conversation_turns')
            ->where('conversation_id', $conversationId)
            ->orderBy('turn_index');

        if ($limit !== null) {
            // The LAST n turns, then re-ordered: a model needs the most recent
            // context, and `limit` on an ascending sort would hand it the oldest.
            $ids = DB::table('ai_conversation_turns')
                ->where('conversation_id', $conversationId)
                ->orderByDesc('turn_index')
                ->limit($limit)
                ->pluck('id');

            $query->whereIn('id', $ids);
        }

        return $query->get()->all();
    }

    /**
     * The transcript as a message list the model client can send.
     *
     * Failed turns are excluded. A turn whose `error` is set has no usable assistant
     * content, and replaying it would either send an empty message or send the error
     * text to the model as though the assistant had said it.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function contextMessages(int $conversationId, int|string|null $institute): array
    {
        $turns = $this->turns($conversationId, $institute, self::CONTEXT_TURNS);

        $messages = [];

        foreach ($turns as $turn) {
            if ($turn->error !== null || trim((string) $turn->content) === '') {
                continue;
            }

            $messages[] = [
                'role' => $turn->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $turn->content,
            ];
        }

        return $messages;
    }

    /**
     * Append one turn and keep the conversation's counters honest.
     *
     * @param  array<string, mixed>  $meta
     */
    public function addTurn(
        int $conversationId,
        int|string|null $institute,
        string $role,
        string $content,
        array $meta = []
    ): int {
        $nextIndex = (int) DB::table('ai_conversation_turns')
            ->where('conversation_id', $conversationId)
            ->max('turn_index');

        $id = DB::table('ai_conversation_turns')->insertGetId([
            'conversation_id' => $conversationId,
            'turn_index' => $nextIndex + 1,
            'role' => $role,
            'content' => $content,
            'provider' => $meta['provider'] ?? null,
            'model' => $meta['model'] ?? null,
            'input_tokens' => $meta['input_tokens'] ?? null,
            'output_tokens' => $meta['output_tokens'] ?? null,
            'latency_ms' => $meta['latency_ms'] ?? null,
            'finish_reason' => $meta['finish_reason'] ?? null,
            'error' => $meta['error'] ?? null,
            'sub_institute_id' => $institute,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_conversations')->where('id', $conversationId)->update([
            'turn_count' => DB::raw('turn_count + 1'),
            'last_turn_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    /**
     * Name the conversation after its first question, if it has no name.
     *
     * A list of conversations all called "Conversation" is a list nobody can navigate,
     * and asking a user to title a chat before they have had it is a question they
     * cannot answer. The first question is the best title available and costs nothing.
     */
    public function titleFromFirstMessage(int $conversationId, string $message): void
    {
        $conversation = DB::table('ai_conversations')->where('id', $conversationId)->first();

        if ($conversation === null || trim((string) $conversation->title) !== '') {
            return;
        }

        $title = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        DB::table('ai_conversations')->where('id', $conversationId)->update([
            'title' => mb_substr($title, 0, 180),
            'updated_at' => now(),
        ]);
    }

    /**
     * This organisation's conversations, most recent first.
     *
     * @return array<int, object>
     */
    public function recent(int|string|null $institute, int $limit = 25): array
    {
        if (! Schema::hasTable('ai_conversations')) {
            return [];
        }

        return DB::table('ai_conversations')
            ->where('sub_institute_id', $institute)
            // Nulls last: a conversation created but never used sorts below every
            // conversation that has actually been held.
            ->orderByRaw('last_turn_at IS NULL ASC')
            ->orderByDesc('last_turn_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}
