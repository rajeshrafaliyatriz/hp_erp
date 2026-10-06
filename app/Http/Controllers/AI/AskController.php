<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Conversation\AskPipeline;
use App\Domain\AI\Conversation\ConversationStore;
use App\Domain\AI\Conversation\OrganisationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Conversational AI — the assistant's front door.
 *
 * Mirrors LMS K-12's `AskController` in shape and in the paths it serves, so a client
 * written against one product reaches the same endpoints on the other. What it does
 * not mirror is the fifteen-stage lifecycle behind LMS's version; see `AskPipeline`
 * for why reproducing that shape here would have produced mostly pass-throughs.
 *
 * WHAT MOVED, AND WHY IT HAD TO
 *
 * G2G's assistant already worked, in the frontend: `app/api/ai/chat/route.ts` held
 * its own Gemini key and kept its transcripts in a module-level `Map`. So the
 * provider an administrator configured under AI Providers had no effect on it, and
 * the conversation count in the console could only be read from tables a different
 * application writes.
 *
 * Serving it from here fixes both by construction: the call resolves through
 * `AiConfigurationResolver` like every other AI module, and the transcript is a row.
 *
 * NOTHING HERE STREAMS
 *
 * LMS offers `/ask/stream` beside `/ask`. It is deliberately absent rather than
 * stubbed: streaming from Laravel through the existing frontend would need an SSE
 * transport on both sides, and a route that accepts a request and then returns the
 * whole answer at once while calling itself a stream is worse than no route. The
 * assistant waits for a complete answer, which is what the panel already did.
 */
class AskController extends AiController
{
    public function __construct(
        private readonly AskPipeline $pipeline,
        private readonly ConversationStore $conversations,
        private readonly OrganisationContext $context,
    ) {
    }

    /**
     * Ask a question.
     *
     * `session_key` is supplied by the client so a browser can keep one conversation
     * across page loads before any turn has been stored. It is not trusted for
     * anything but identity within the caller's own organisation — the unique index
     * is on (session_key, sub_institute_id), so one organisation cannot reach
     * another's conversation by guessing a key.
     */
    public function ask(Request $request)
    {
        try {
            $scope = $this->scope($request);

            $validated = $request->validate([
                'message' => 'required|string|max:8000',
                'session_key' => 'nullable|string|max:64',
                'module_key' => 'nullable|string|max:60',
            ]);

            $result = $this->pipeline->ask(
                $scope,
                // A key the caller did not supply is generated rather than refused:
                // the first question of a new conversation legitimately has none.
                trim((string) ($validated['session_key'] ?? '')) ?: (string) \Illuminate\Support\Str::uuid(),
                trim($validated['message']),
                $validated['module_key'] ?? null,
                $this->organisationName($scope->selectedInstituteId)
            );

            // A failed answer is a 200 carrying `answer: null` and a reason, not a
            // 500. The question was accepted and recorded; what failed was the
            // provider, and the screen needs to render the transcript either way.
            return $this->success(
                $result['answer'] === null ? 'The assistant could not answer.' : 'Answered.',
                $result
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** This organisation's conversations, most recent first. */
    public function conversations(Request $request)
    {
        try {
            $scope = $this->scope($request);

            $rows = $this->conversations->recent(
                $scope->selectedInstituteId,
                $this->limit($request, 25, 100)
            );

            return $this->success('Conversations resolved.', [
                'sub_institute_id' => $scope->selectedInstituteId,
                'conversations' => array_map(fn ($row) => [
                    'id' => (int) $row->id,
                    'session_key' => (string) $row->session_key,
                    'title' => $row->title === null ? null : (string) $row->title,
                    'module_key' => $row->module_key === null ? null : (string) $row->module_key,
                    'turn_count' => (int) $row->turn_count,
                    'status' => (string) $row->status,
                    'last_turn_at' => $row->last_turn_at === null ? null : (string) $row->last_turn_at,
                    'created_at' => $row->created_at === null ? null : (string) $row->created_at,
                ], $rows),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * One transcript in full.
     *
     * Turns carry which model produced them and what they cost. That is the half a
     * reader needs when an answer looks wrong — and it is per turn rather than per
     * conversation because a model can be reconfigured mid-conversation.
     */
    public function conversation(Request $request, int $conversation)
    {
        try {
            $scope = $this->scope($request);
            $institute = $scope->selectedInstituteId;

            $row = $this->conversations->find($conversation, $institute);

            if ($row === null) {
                return $this->failure('That conversation was not found.', 404);
            }

            $turns = $this->conversations->turns($conversation, $institute);

            return $this->success('Conversation resolved.', [
                'conversation' => [
                    'id' => (int) $row->id,
                    'session_key' => (string) $row->session_key,
                    'title' => $row->title === null ? null : (string) $row->title,
                    'module_key' => $row->module_key === null ? null : (string) $row->module_key,
                    'turn_count' => (int) $row->turn_count,
                    'status' => (string) $row->status,
                    'last_turn_at' => $row->last_turn_at === null ? null : (string) $row->last_turn_at,
                ],
                'turns' => array_map(fn ($turn) => [
                    'id' => (int) $turn->id,
                    'turn_index' => (int) $turn->turn_index,
                    'role' => (string) $turn->role,
                    'content' => (string) $turn->content,
                    'provider' => $turn->provider === null ? null : (string) $turn->provider,
                    'model' => $turn->model === null ? null : (string) $turn->model,
                    'input_tokens' => $turn->input_tokens === null ? null : (int) $turn->input_tokens,
                    'output_tokens' => $turn->output_tokens === null ? null : (int) $turn->output_tokens,
                    'latency_ms' => $turn->latency_ms === null ? null : (int) $turn->latency_ms,
                    // Surfaced rather than hidden: a turn that failed is part of the
                    // transcript, and a screen that omits it shows a question with no
                    // answer and no explanation.
                    'error' => $turn->error === null ? null : (string) $turn->error,
                    'created_at' => $turn->created_at === null ? null : (string) $turn->created_at,
                ], $turns),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * What the assistant currently knows about this organisation.
     *
     * Exists so the capability screen can show the grounding rather than assert it.
     * "This assistant is grounded in your data" is a claim; the figures it was given
     * are the evidence, and an administrator who can read them can tell whether an
     * answer should have been possible.
     */
    public function groundingContext(Request $request)
    {
        try {
            $institute = $this->scope($request)->selectedInstituteId;
            $briefing = $this->context->briefing($institute);

            return $this->success('Grounding context resolved.', [
                'sub_institute_id' => $institute,
                'grounded' => $briefing !== null,
                // Parsed into rows for the screen. The model gets the same facts as
                // text; this is the same content shaped for a table.
                'facts' => $this->parseBriefing($briefing),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * The briefing's "- Label: value" lines as structured rows.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function parseBriefing(?string $briefing): array
    {
        if ($briefing === null) {
            return [];
        }

        $facts = [];

        foreach (explode("\n", $briefing) as $line) {
            if (! preg_match('/^-\s*(.+?):\s*(.+)$/', trim($line), $matches)) {
                continue;
            }

            $facts[] = ['label' => trim($matches[1]), 'value' => trim($matches[2])];
        }

        return $facts;
    }

    /**
     * The organisation's own name, so the assistant addresses it by name.
     *
     * Read from `school_setup`, which is where this platform keeps it. Falls back to
     * a generic phrase rather than a hardcoded name — an assistant greeting every
     * organisation as somebody else's is worse than one being vague.
     */
    private function organisationName(int|string $institute): ?string
    {
        if (! Schema::hasTable('school_setup')) {
            return null;
        }

        $row = DB::table('school_setup')->where('id', $institute)->first();

        if ($row === null) {
            return null;
        }

        // `SchoolName` first because that is the column this platform actually uses;
        // the rest are tried in case a deployment renamed it. Listing only the
        // lowercase spellings is how this silently returned null everywhere.
        foreach (['SchoolName', 'name', 'school_name', 'institute_name', 'org_name'] as $column) {
            $value = trim((string) ($row->{$column} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
