<?php

namespace App\Http\Controllers\Api\Facts;

use App\Domain\Facts\FactAccess;
use App\Domain\Facts\FactEntities;
use App\Domain\Facts\FactNotFound;
use App\Domain\Facts\FactPresenter;
use App\Domain\Facts\FactWriter;
use App\Domain\Facts\SuggestionQueue;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The facts API (slice 4 of the agentic CRM, docs/agentic-crm/slices/S04-contact-facts-and-consent.md "Contract"). Routes and rights are in routes/facts.php.
 *
 * The organisation always comes from the caller's token, never from the request. Every read and write of a fact or of the record it is about filters by it,
 * and another organisation's id is answered with the same 404 as one that does not exist, so a probe learns nothing. There is no route that PROPOSES a
 * fact: the agent tool (slice 7) and the discovery sources (slice 5) call FactWriter::propose(); a person here only accepts or dismisses.
 */
class FactsController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /entity/{contact|organization|lead}/{id}: the facts of one record, with the evidence of each. */
    public function entity(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $type = (string) $request->route('entityType');
        $record = FactEntities::find($t, $type, $id);
        if ($record === null) {
            return $this->notFound('Record not found.');
        }

        $queue = app(SuggestionQueue::class);

        return response()->json(['status' => 1, 'data' => [
            'entity' => ['type' => $type, 'id' => (int) $record->id, 'name' => FactEntities::name($type, $record)],
            'facts' => (new FactPresenter($t))->facts($queue->forEntity($t, $type, $id)),
            'counts' => $queue->counts($t, $type, $id),
            // what the screen may offer: a person without the edit right never sees a button that cannot work (the server still enforces it)
            'can' => ['decide' => FactAccess::can($identity, $type, 'edit')],
        ]]);
    }

    /** GET /queue?type=&status=proposed&page=&per_page=: the organisation's suggestions, newest first, only for the kinds of record the caller may view. */
    public function queue(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $validator = Validator::make($request->query(), [
            'type' => ['nullable', 'in:' . implode(',', FactEntities::TYPES)], 'status' => ['nullable', 'in:' . implode(',', SuggestionQueue::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $f = $validator->validated();

        $viewable = FactAccess::types($identity, 'view');
        if (! empty($f['type'])) {
            if (! in_array($f['type'], $viewable, true)) {
                return $this->noRight();
            }
            $viewable = [$f['type']];
        }
        if ($viewable === []) {
            return $this->noRight();
        }

        $max = (int) config('facts.queue.max_per_page', 50);
        $perPage = max(1, min($max, (int) ($f['per_page'] ?? config('facts.queue.default_per_page', 25))));
        $page = max(1, (int) ($f['page'] ?? 1));
        $result = app(SuggestionQueue::class)->page($t, $viewable, (string) ($f['status'] ?? 'proposed'), $page, $perPage);

        $names = FactEntities::names($t, array_map(fn ($r) => [(string) $r->entity_type, (int) $r->entity_id], $result['rows']));
        $rows = array_map(fn (array $fact) => $fact + ['entity_name' => $names["{$fact['entity_type']}:{$fact['entity_id']}"] ?? null], (new FactPresenter($t))->facts($result['rows']));

        $decide = array_values(array_intersect($viewable, FactAccess::types($identity, 'edit')));

        return response()->json(['status' => 1, 'data' => ['rows' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total']],
            'can' => ['decide' => $decide !== [], 'decide_types' => $decide]]]);
    }

    /** POST /{id}/accept */
    public function accept(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if (($denied = $this->mayDecide($identity, $id)) !== null) {
            return $denied;
        }

        try {
            $result = app(FactWriter::class)->accept($t, $id, $identity['user_id']);
        } catch (FactNotFound) {
            return $this->notFound('Fact not found.');
        }

        return $this->answer($t, $result, 'This fact was already decided.');
    }

    /** POST /{id}/dismiss {reason?} */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $validator = Validator::make($request->all(), ['reason' => ['nullable', 'string', 'max:500']], ['reason.max' => 'A reason can have at most 500 characters.']);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        if (($denied = $this->mayDecide($identity, $id)) !== null) {
            return $denied;
        }

        try {
            $result = app(FactWriter::class)->dismiss($t, $id, $identity['user_id'], $request->input('reason'));
        } catch (FactNotFound) {
            return $this->notFound('Fact not found.');
        }

        return $this->answer($t, $result, 'This fact was already decided.');
    }

    // ---- helpers ----

    /**
     * The route middleware let the caller in because they may edit SOME kind of record; this fact belongs to one kind, and the right is that kind's. Another
     * organisation's fact, and one that never existed, are the same 404 (looked up with the caller's organisation).
     *
     * @param  array{user: object, user_id: int, sub_institute_id: int}  $identity
     */
    private function mayDecide(array $identity, int $factId): ?JsonResponse
    {
        $type = DB::table('g2g_entity_facts')->where('sub_institute_id', $identity['sub_institute_id'])->where('id', $factId)->value('entity_type');
        if ($type === null) {
            return $this->notFound('Fact not found.');
        }

        return FactAccess::can($identity, (string) $type, 'edit') ? null : $this->noRight();
    }

    /** @param array{outcome: string, fact: object, column_written?: bool} $result */
    private function answer(int $tenantId, array $result, string $notPending): JsonResponse
    {
        $fact = (new FactPresenter($tenantId))->facts([$result['fact']])[0];

        return match ($result['outcome']) {
            FactWriter::APPLIED, FactWriter::DISMISSED => response()->json(['status' => 1, 'data' => ['outcome' => $result['outcome'], 'fact' => $fact]]),
            FactWriter::ENTITY_GONE => response()->json(['status' => 0, 'code' => 'entity_gone', 'message' => 'The record this fact is about was removed. Nothing was changed.', 'data' => ['fact' => $fact]], 409),
            default => response()->json(['status' => 0, 'code' => 'not_pending', 'message' => $notPending, 'data' => ['fact' => $fact]], 409),
        };
    }

    private function noRight(): JsonResponse
    {
        return response()->json(['status' => 0, 'code' => 'no_right', 'message' => 'Your role does not have access to this screen.'], 403);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], 404);
    }

    /** @param array<string, list<string>> $errors field => messages */
    private function invalid(array $errors): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => (string) (collect($errors)->flatten()->first() ?? 'The request is not valid.'), 'errors' => $errors], 422);
    }
}
