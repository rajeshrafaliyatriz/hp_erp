<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Generic flat, append-only comment thread for Lead/Contact/Organization/
 * Opportunity - the same concept CrmTicketController's own
 * getComments()/addComment()/commentResource() already implements for
 * crm_ticket_comments/ticket_id (untouched - Ticket already has working
 * comments and never `use`s this trait), generalized here via 3 small config
 * methods instead of hardcoding a table/column, so one trait serves all 4
 * hosts rather than 4 near-duplicate copies - same reasoning
 * HasCrmBulkActions's own doc-comment gives for bulk delete/assign.
 *
 * A host controller `use`-ing this trait must implement:
 *   - commentsTable(): string - e.g. 'crm_lead_comments'.
 *   - commentsForeignKey(): string - e.g. 'lead_id'.
 *   - ownRow(int $tenantId, int $id): ?object - the host's own existing
 *     tenant-scoped, not-soft-deleted row lookup (every CRM controller
 *     already has one, as its update()/destroy() 404 guard) - the trait
 *     calls it, never redefines it.
 *
 * No soft-deletes here: comment rows are append-only, cascade-deleted by the
 * database when their parent row is hard-deleted.
 */
trait HasCrmComments
{
    /** Oldest-first, same order CrmTicketController::getComments() already uses. */
    public function getComments(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        $table = $this->commentsTable();
        $foreignKey = $this->commentsForeignKey();

        $rows = DB::table("{$table} as tc")
            ->leftJoin('tbluser as u', 'u.id', '=', 'tc.created_by')
            ->where("tc.{$foreignKey}", $id)
            ->orderBy('tc.created_at')
            ->select('tc.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"))
            ->get();

        return response()->json([
            'status' => 1,
            'message' => 'Comments.',
            'data' => $rows->map(fn ($row) => $this->commentResource($row))->all(),
        ]);
    }

    public function addComment(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        $validator = Validator::make($request->all(), ['comment' => 'required|string']);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $table = $this->commentsTable();
        $foreignKey = $this->commentsForeignKey();

        $commentId = DB::table($table)->insertGetId([
            $foreignKey => $id,
            'sub_institute_id' => $identity['sub_institute_id'],
            'comment' => $request->input('comment'),
            'created_by' => $identity['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table("{$table} as tc")
            ->leftJoin('tbluser as u', 'u.id', '=', 'tc.created_by')
            ->where('tc.id', $commentId)
            ->select('tc.*', DB::raw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) as created_by_name"))
            ->first();

        return response()->json(['status' => 1, 'message' => 'Comment added.', 'data' => $this->commentResource($row)], 201);
    }

    /** @return array<string, mixed> */
    private function commentResource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'comment' => $row->comment,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdByName' => ($row->created_by_name ?? '') !== '' ? $row->created_by_name : null,
            'createdAt' => $row->created_at,
        ];
    }
}
