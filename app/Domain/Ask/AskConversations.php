<?php

namespace App\Domain\Ask;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The conversations of the Ask chat and their messages (slice 8): who may read them, and the JSON shapes they are read in.
 *
 * A conversation is its owner's and nobody else's. Every lookup carries the organisation AND the person, and a conversation that is another person's, in
 * the same organisation or in another, answers exactly like one that was never there (null here, 404 in the API): a probe cannot tell the three apart.
 * A message is only ever reached through the caller's own conversation. An archived conversation is not listed and cannot be opened or asked into.
 */
final class AskConversations
{
    /** The caller's own live conversation, or null (not theirs, not their organisation's, archived, or never there). */
    public function find(AskCaller $caller, int $id): ?object
    {
        return DB::table('g2g_agent_conversations')->where('sub_institute_id', $caller->tenantId)->where('user_id', $caller->userId)->whereNull('archived_at')->where('id', $id)->first();
    }

    /**
     * The caller's conversations, newest first.
     *
     * @return array{rows: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function list(AskCaller $caller, int $page, int $perPage): array
    {
        $query = DB::table('g2g_agent_conversations')->where('sub_institute_id', $caller->tenantId)->where('user_id', $caller->userId)->whereNull('archived_at');
        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, $perPage)->get();

        $counts = $rows->isEmpty() ? [] : DB::table('g2g_agent_messages')->where('sub_institute_id', $caller->tenantId)->whereIn('conversation_id', $rows->pluck('id')->all())
            ->selectRaw('conversation_id, COUNT(*) AS n')->groupBy('conversation_id')->pluck('n', 'conversation_id')->all();

        return [
            'rows' => $rows->map(fn ($c) => $this->conversation($c, (int) ($counts[$c->id] ?? 0)))->values()->all(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /**
     * One conversation with its messages, oldest first (the newest `ask.max_messages_read` of them).
     *
     * @return array<string, mixed>|null null when the conversation is not the caller's
     */
    public function show(AskCaller $caller, int $id): ?array
    {
        $conversation = $this->find($caller, $id);
        if ($conversation === null) {
            return null;
        }
        $messages = DB::table('g2g_agent_messages')->where('sub_institute_id', $caller->tenantId)->where('conversation_id', $conversation->id)
            ->orderByDesc('id')->limit((int) config('ask.max_messages_read', 100))->get()->reverse()->values();

        return $this->conversation($conversation, (int) DB::table('g2g_agent_messages')->where('sub_institute_id', $caller->tenantId)->where('conversation_id', $conversation->id)->count())
            + ['messages' => $messages->map(fn ($m) => $this->message($m))->all()];
    }

    /** @return array<string, mixed> */
    public function conversation(object $c, int $messageCount): array
    {
        return [
            'id' => (int) $c->id, 'title' => (string) $c->title,
            'context' => $c->context_type !== null && $c->context_id !== null ? ['type' => (string) $c->context_type, 'id' => (int) $c->context_id] : null,
            'message_count' => $messageCount, 'created_at' => $this->iso($c->created_at), 'updated_at' => $this->iso($c->updated_at),
        ];
    }

    /**
     * A message as the API answers with it. Citations are read back as stored; a stored value that is not a list of references reads as none.
     *
     * @return array{id: int, role: string, text: string, status: ?string, citations: list<array{type: string, id: string, label: string, url: ?string}>, created_at: ?string}
     */
    public function message(object $m): array
    {
        $stored = $m->citations !== null ? json_decode((string) $m->citations, true) : null;
        $citations = [];
        foreach (is_array($stored) ? $stored : [] as $c) {
            if (is_array($c) && is_string($c['type'] ?? null) && isset($c['id']) && is_string($c['label'] ?? null)) {
                $citations[] = ['type' => $c['type'], 'id' => (string) $c['id'], 'label' => $c['label'], 'url' => is_string($c['url'] ?? null) ? $c['url'] : null];
            }
        }

        return ['id' => (int) $m->id, 'role' => (string) $m->role, 'text' => (string) $m->content, 'status' => $m->status, 'citations' => $citations, 'created_at' => $this->iso($m->created_at)];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
