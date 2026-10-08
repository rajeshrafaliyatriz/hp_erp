<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Support\AiAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Approval ledger for assistant-proposed actions.
 *
 * This controller never performs the action. The browser does, through the application's own
 * API client as the signed-in user, so existing permissions apply unchanged. What lives here is
 * the decision and the lifecycle, enforced server-side:
 *
 *   pending -> approved -> executing -> completed | failed
 *   pending -> rejected | cancelled
 *
 *  - Only an administrator of the tenant may approve or reject, and never their own request.
 *  - `claim` is an atomic approved -> executing transition, so an approved action runs once
 *    even if two tabs try.
 *  - Every transition is tenant-scoped and audited.
 */
class ActionRequestController extends AiController
{
    private const TABLE = 'ai_action_requests';

    public function __construct(private readonly AiAuditLogger $audit)
    {
    }

    /** Requests the caller made (`mine`, default) or, for administrators, those awaiting a decision. */
    public function index(Request $request)
    {
        try {
            $scope = $this->scope($request);

            if (! Schema::hasTable(self::TABLE)) {
                return $this->success('No action requests.', ['requests' => []]);
            }

            $query = DB::table(self::TABLE)->where('sub_institute_id', $scope->selectedInstituteId);

            if ($request->query('view') === 'all') {
                // The module's whole ledger, every status - for the AI Stack's Approvals tab.
                if (! $scope->isAdmin) {
                    return $this->failure('Only administrators can see the module approvals ledger.', 403);
                }
            } elseif ($request->query('view') === 'pending') {
                if (! $scope->isAdmin) {
                    return $this->failure('Only administrators can see requests awaiting approval.', 403);
                }
                $query->where('status', 'pending')->where('requested_by', '!=', $scope->userId);
            } else {
                $query->where('requested_by', $scope->userId);
            }

            if ($module = $request->query('module_key')) {
                // `rollup=1`: a top-level module also owns its screens' requests.
                $keys = $request->boolean('rollup')
                    ? app(\App\Domain\AI\Conversation\ModuleGrounding::class)->keys((string) $module)
                    : [(string) $module];
                $query->whereIn('module_key', $keys);
            }

            $rows = $query->orderByDesc('id')->limit($this->limit($request, 50, 200))->get();

            return $this->success('Action requests resolved.', [
                'requests' => $rows->map(fn ($row) => $this->present($row))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request)
    {
        try {
            $scope = $this->scope($request);

            $validated = $request->validate([
                'action_key' => 'required|string|max:120',
                'module_key' => 'nullable|string|max:120',
                'payload' => 'required|array',
                'preview' => 'nullable|array',
                'conversation_id' => 'nullable|integer|min:1',
            ]);

            $id = DB::table(self::TABLE)->insertGetId([
                'sub_institute_id' => $scope->selectedInstituteId,
                'client_id' => $scope->clientId,
                'module_key' => $validated['module_key'] ?? null,
                'action_key' => $validated['action_key'],
                'requested_by' => $scope->userId,
                'payload' => json_encode($validated['payload']),
                'preview' => isset($validated['preview']) ? json_encode($validated['preview']) : null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ] + $this->conversationLink($validated['conversation_id'] ?? null, $scope));

            $this->audit($scope, 'requested', $id, $validated['action_key'], $validated['module_key'] ?? null);

            return $this->success('Sent for approval.', ['request' => $this->find($id, $scope)], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Tie the request to the conversation that proposed it - only a conversation of this tenant and
     * this user, only when the column is installed. Anything else is silently left unlinked.
     *
     * @return array<string, int>
     */
    private function conversationLink(?int $conversationId, $scope): array
    {
        if ($conversationId === null || ! Schema::hasColumn(self::TABLE, 'conversation_id')) {
            return [];
        }

        $owned = DB::table('ai_conversations')
            ->where('id', $conversationId)
            ->where('sub_institute_id', $scope->selectedInstituteId)
            ->where('user_id', $scope->userId)
            ->exists();

        return $owned ? ['conversation_id' => $conversationId] : [];
    }

    public function show(Request $request, int $actionRequest)
    {
        try {
            $scope = $this->scope($request);
            $row = $this->find($actionRequest, $scope);

            if ($row === null || ! $this->visible($row, $scope)) {
                return $this->failure('That request was not found.', 404);
            }

            return $this->success('Action request resolved.', ['request' => $row]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function resolve(Request $request, int $actionRequest)
    {
        try {
            $scope = $this->scope($request);

            $validated = $request->validate([
                'decision' => 'required|string|in:approved,rejected',
                'note' => 'nullable|string|max:2000',
            ]);

            if (! $scope->isAdmin) {
                return $this->failure('Only administrators can approve or reject requests.', 403);
            }

            $row = $this->find($actionRequest, $scope);
            if ($row === null) {
                return $this->failure('That request was not found.', 404);
            }
            if ((int) $row['requested_by'] === $scope->userId) {
                return $this->failure('You cannot decide your own request.', 403);
            }

            $changed = DB::table(self::TABLE)
                ->where('id', $actionRequest)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('status', 'pending')
                ->update([
                    'status' => $validated['decision'],
                    'decided_by' => $scope->userId,
                    'decided_at' => now(),
                    'decision_note' => $validated['note'] ?? null,
                    'updated_at' => now(),
                ]);

            if ($changed === 0) {
                return $this->failure('This request was already decided.', 409);
            }

            $this->audit($scope, $validated['decision'], $actionRequest, $row['action_key'], $row['module_key']);

            return $this->success(
                $validated['decision'] === 'approved' ? 'Approved.' : 'Rejected.',
                ['request' => $this->find($actionRequest, $scope)]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** The requester starts execution: approved -> executing, once. */
    public function claim(Request $request, int $actionRequest)
    {
        return $this->transition($request, $actionRequest, 'approved', 'executing', 'claimed', true);
    }

    /** The requester reports the outcome of the execution they ran. */
    public function complete(Request $request, int $actionRequest)
    {
        try {
            $scope = $this->scope($request);
            $validated = $request->validate([
                'ok' => 'required|boolean',
                'message' => 'nullable|string|max:2000',
            ]);

            $row = $this->find($actionRequest, $scope);
            if ($row === null || (int) $row['requested_by'] !== $scope->userId) {
                return $this->failure('That request was not found.', 404);
            }

            $status = $validated['ok'] ? 'completed' : 'failed';
            $changed = DB::table(self::TABLE)
                ->where('id', $actionRequest)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('status', 'executing')
                ->update([
                    'status' => $status,
                    'executed_at' => now(),
                    'result' => json_encode(['ok' => (bool) $validated['ok'], 'message' => $validated['message'] ?? null]),
                    'updated_at' => now(),
                ]);

            if ($changed === 0) {
                return $this->failure('This request is not executing.', 409);
            }

            $this->audit($scope, $status, $actionRequest, $row['action_key'], $row['module_key']);

            return $this->success('Recorded.', ['request' => $this->find($actionRequest, $scope)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function cancel(Request $request, int $actionRequest)
    {
        return $this->transition($request, $actionRequest, 'pending', 'cancelled', 'cancelled', true);
    }

    private function transition(Request $request, int $id, string $from, string $to, string $event, bool $requesterOnly)
    {
        try {
            $scope = $this->scope($request);
            $row = $this->find($id, $scope);

            if ($row === null || ($requesterOnly && (int) $row['requested_by'] !== $scope->userId)) {
                return $this->failure('That request was not found.', 404);
            }

            $changed = DB::table(self::TABLE)
                ->where('id', $id)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('status', $from)
                ->update(['status' => $to, 'updated_at' => now()]);

            if ($changed === 0) {
                return $this->failure("This request is no longer {$from}.", 409);
            }

            $this->audit($scope, $event, $id, $row['action_key'], $row['module_key']);

            return $this->success('Updated.', ['request' => $this->find($id, $scope)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function find(int $id, $scope): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('id', $id)
            ->where('sub_institute_id', $scope->selectedInstituteId)
            ->first();

        return $row ? $this->present($row) : null;
    }

    private function visible(array $row, $scope): bool
    {
        return (int) $row['requested_by'] === $scope->userId || $scope->isAdmin;
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'module_key' => $row->module_key,
            'action_key' => $row->action_key,
            'requested_by' => (int) $row->requested_by,
            'payload' => json_decode($row->payload ?? 'null', true),
            'preview' => json_decode($row->preview ?? 'null', true),
            'status' => $row->status,
            'decided_by' => $row->decided_by !== null ? (int) $row->decided_by : null,
            'decided_at' => $row->decided_at,
            'decision_note' => $row->decision_note,
            'executed_at' => $row->executed_at,
            'result' => json_decode($row->result ?? 'null', true),
            'created_at' => $row->created_at,
        ];
    }

    private function audit($scope, string $event, int $id, string $actionKey, ?string $moduleKey): void
    {
        $this->audit->record("ai.action_request.{$event}", $scope, [
            'related_type' => self::TABLE,
            'subject_entity_key' => 'action_request',
            'message' => "Action request #{$id} ({$actionKey}) {$event}.",
            'payload' => ['request_id' => $id, 'action_key' => $actionKey, 'module_key' => $moduleKey],
        ]);
    }
}
