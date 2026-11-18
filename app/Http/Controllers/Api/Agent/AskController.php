<?php

namespace App\Http\Controllers\Api\Agent;

use App\Domain\AgentGateway\AgentRefused;
use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskConversations;
use App\Domain\Ask\AskService;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The Ask chat's API (slice 8 of the agentic CRM, Docs/agentic-crm/slices/S08-ask.md). Routes and rights are in routes/agent_gateway.php.
 *
 * The organisation and the person always come from the caller's token, never from the request, and a conversation is its owner's alone: another person's id
 * and another organisation's id both answer 404, the same body as an id that never existed. The questions are answered by AskService; nothing here reads a
 * record or calls a model.
 */
class AskController extends Controller
{
    use ResolvesApiIdentity;

    private const MAX_PER_PAGE = 50;

    /**
     * POST /ask {message, conversation_id?, context?: {type, id}}
     *
     * 200 `{status: 1, data: {conversation_id, message: {id, role: 'assistant', text, status, citations, created_at}}}`; `status` is answered | no_data | refused |
     * unavailable (a model that is not set up, or that failed, is an answer with status `unavailable`, never an HTTP error). 422 validation, 404 a conversation
     * that is not the caller's, 429 `{code: 'daily_limit', limit}` past the per-person daily cap.
     */
    public function ask(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:' . (int) config('ask.max_message_chars', 1000)],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'context' => ['nullable', 'array'],
            'context.type' => ['required_with:context', 'string', 'in:lead,contact,organization,opportunity'],
            'context.id' => ['required_with:context', 'integer', 'min:1'],
        ], ['message.max' => 'A question can have at most ' . (int) config('ask.max_message_chars', 1000) . ' characters.']);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $caller = AskCaller::fromIdentity($identity);
        try {
            $result = app(AskService::class)->ask(
                $caller,
                (string) $request->input('message'),
                $request->filled('conversation_id') ? (int) $request->input('conversation_id') : null,
                is_array($request->input('context')) ? $request->input('context') : null,
            );
        } catch (AgentRefused $e) {
            return $this->refused($e);
        }

        return response()->json(['status' => 1, 'data' => $result]);
    }

    /** GET /conversations?page=&per_page= : the caller's own conversations, newest first. */
    public function conversations(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $validator = Validator::make($request->query(), ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1']]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($request->query('per_page') ?: config('ask.conversations_per_page', 20))));
        $page = max(1, (int) $request->query('page', 1));

        return response()->json(['status' => 1, 'data' => app(AskConversations::class)->list(AskCaller::fromIdentity($identity), $page, $perPage)]);
    }

    /** GET /conversations/{id} : one of the caller's own conversations with its messages, oldest first. */
    public function conversation(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $conversation = app(AskConversations::class)->show(AskCaller::fromIdentity($identity), $id);

        return $conversation === null
            ? response()->json(['status' => 0, 'message' => 'Conversation not found.'], 404)
            : response()->json(['status' => 1, 'data' => $conversation]);
    }

    private function refused(AgentRefused $e): JsonResponse
    {
        $body = ['status' => 0, 'code' => $e->reason, 'message' => $e->getMessage()];
        if ($e->reason === 'daily_limit') {
            $body += ['limit' => (int) config('ask.daily_question_cap', 30)];
        }

        return response()->json($body, $e->status);
    }

    /** @param array<string, list<string>> $errors field => messages */
    private function invalid(array $errors): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => (string) (collect($errors)->flatten()->first() ?? 'The request is not valid.'), 'errors' => $errors], 422);
    }
}
