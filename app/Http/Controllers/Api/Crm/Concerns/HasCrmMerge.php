<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Shared merge mechanics for Leads/Contacts/Organizations: fill the
 * survivor's blank fields from each duplicate, let the caller re-point
 * whatever else references the duplicate, then soft-delete it (into the
 * Recycle Bin, same as a normal delete - merge is significant enough that
 * "undo" should stay possible, not a hard delete).
 */
trait HasCrmMerge
{
    /** @var array<string> Columns the generic field-supplement pass never touches. */
    private const MERGE_SKIP_COLUMNS = [
        'id', 'sub_institute_id', 'created_by', 'updated_by', 'deleted_by',
        'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * @param (Closure(int $fromId, int $toId): void)|null $repoint Re-point
     *   any other table's references to the duplicate before it is
     *   soft-deleted (e.g. crm_campaign_targets, a self-referencing
     *   parent/reports-to column).
     */
    protected function mergeRows(
        Request $request,
        string $table,
        int $tenantId,
        int $userId,
        string $label,
        ?Closure $repoint = null,
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'survivorId' => 'required|integer|min:1',
            'duplicateIds' => 'required|array|min:1|max:20',
            'duplicateIds.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $survivorId = (int) $request->input('survivorId');
        $duplicateIds = array_values(array_diff(
            array_unique(array_map('intval', $request->input('duplicateIds'))),
            [$survivorId],
        ));

        if ($duplicateIds === []) {
            return response()->json(['status' => 0, 'message' => 'Select at least one duplicate other than the survivor.'], 422);
        }

        $survivor = DB::table($table)->where('id', $survivorId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->first();

        if (! $survivor) {
            return response()->json(['status' => 0, 'message' => "{$label} not found."], 404);
        }

        $columns = array_diff(Schema::getColumnListing($table), self::MERGE_SKIP_COLUMNS);
        $merged = 0;

        DB::transaction(function () use ($table, $tenantId, $userId, $survivorId, &$survivor, $duplicateIds, $columns, $repoint, &$merged) {
            foreach ($duplicateIds as $duplicateId) {
                $duplicate = DB::table($table)->where('id', $duplicateId)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->first();

                if (! $duplicate) {
                    continue;
                }

                $fill = [];
                foreach ($columns as $column) {
                    $survivorValue = $survivor->{$column} ?? null;
                    $duplicateValue = $duplicate->{$column} ?? null;

                    if (($survivorValue === null || $survivorValue === '') && $duplicateValue !== null && $duplicateValue !== '') {
                        $fill[$column] = $duplicateValue;
                    }
                }

                if ($fill !== []) {
                    $fill['updated_by'] = $userId;
                    $fill['updated_at'] = now();
                    DB::table($table)->where('id', $survivorId)->update($fill);
                    // Re-fetch so the NEXT duplicate in this batch supplements
                    // against the just-filled survivor, not a stale copy.
                    $survivor = DB::table($table)->where('id', $survivorId)->first();
                }

                if ($repoint) {
                    $repoint($duplicateId, $survivorId);
                }

                DB::table($table)->where('id', $duplicateId)->update([
                    'deleted_at' => now(),
                    'deleted_by' => $userId,
                ]);

                $merged++;
            }
        });

        return response()->json([
            'status' => 1,
            'message' => $merged === 1 ? "1 {$label} merged into the survivor." : "{$merged} {$label}s merged into the survivor.",
            'data' => ['survivorId' => (string) $survivorId, 'merged' => $merged],
        ]);
    }

    /**
     * Move a crm_campaign_targets row from the merged-away duplicate to the
     * survivor. The (campaign_id, target_type, target_id) unique constraint
     * means a campaign that already targets the survivor must not also end
     * up targeting it twice - that row is simply dropped instead of erroring.
     */
    protected function repointCampaignTargets(string $targetType, int $fromId, int $toId): void
    {
        $rows = DB::table('crm_campaign_targets')
            ->where('target_type', $targetType)
            ->where('target_id', $fromId)
            ->get();

        foreach ($rows as $row) {
            $alreadyTargeted = DB::table('crm_campaign_targets')
                ->where('campaign_id', $row->campaign_id)
                ->where('target_type', $targetType)
                ->where('target_id', $toId)
                ->exists();

            if ($alreadyTargeted) {
                DB::table('crm_campaign_targets')->where('id', $row->id)->delete();
            } else {
                DB::table('crm_campaign_targets')->where('id', $row->id)->update(['target_id' => $toId]);
            }
        }
    }
}
