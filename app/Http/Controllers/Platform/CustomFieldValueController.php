<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\CustomFieldValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The answers to custom fields, for one record.
 *
 * ── WHY THIS IS SEPARATE FROM `FieldConfigController` ───────────────────────
 *
 * That one manages DEFINITIONS and is administrator-only: deciding what fields
 * the organisation captures is an administrator's job. This one manages ANSWERS,
 * which is ordinary work — whoever may edit an employee may fill in that
 * employee's fields.
 *
 * They are in the same route group today, so both are administrator-gated. That
 * is the safe direction to start in and it is deliberately noted here: when the
 * employee form is opened to HR, this controller moves to a group that allows
 * them and `FieldConfigController` does not.
 *
 * ── THE RECORD TYPE IS ALWAYS CHECKED ───────────────────────────────────────
 *
 * `record_table` arrives from the caller and is validated against the registry
 * allowlist inside `CustomFieldValues`. Nothing here trusts it, and the
 * controller never interpolates it into a query.
 */
class CustomFieldValueController extends PlatformController
{
    public function __construct(private readonly CustomFieldValues $values)
    {
    }

    /**
     * The fields that apply to a record, with its current answers.
     *
     * Returns an empty list rather than a 404 when the record type has no fields:
     * "this organisation has defined none" is a normal answer, and a form that
     * treats it as an error would refuse to render for every tenant that has not
     * configured any.
     */
    public function show(Request $request, string $recordTable, int $recordId): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            if (! $this->recordBelongsToTenant($recordTable, $recordId, $scope->selectedInstituteId)) {
                return $this->failure('That record no longer exists.', 404);
            }

            return $this->success('Custom fields.', [
                'record_table' => $recordTable,
                'record_id' => $recordId,
                'fields' => $this->values->formFor($recordTable, $recordId, $scope->selectedInstituteId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Save a record's answers.
     *
     * `values` is `{field_id: value}`. A field left out is unchanged, not cleared.
     */
    public function store(Request $request, string $recordTable, int $recordId): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            if (! $this->recordBelongsToTenant($recordTable, $recordId, $scope->selectedInstituteId)) {
                return $this->failure('That record no longer exists.', 404);
            }

            $answers = $request->input('values');

            if (! is_array($answers)) {
                return $this->failure('Send the answers as an object of field id to value.', 422, [
                    'values' => ['Expected an object.'],
                ]);
            }

            $result = $this->values->save(
                $recordTable,
                $recordId,
                $scope->selectedInstituteId,
                $answers,
                $scope->userId
            );

            /*
             * `ignored` is reported rather than swallowed.
             *
             * It means the form sent a field that no longer exists, or one
             * belonging to another record type or organisation. Silently dropping
             * those would make a stale form look like it saved everything.
             */
            return $this->success('Saved.', $result);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Whether the record exists AND belongs to the caller's organisation.
     *
     * Without this an administrator could read or write custom field answers
     * against a record in another tenant simply by changing the id in the URL —
     * the values table would happily store the row, and the answer would then
     * appear on somebody else's employee.
     *
     * A cross-tenant id reads as 404, never 403: 403 would confirm the record
     * exists somewhere, which is a fact about another organisation.
     */
    private function recordBelongsToTenant(string $recordTable, int $recordId, int $tenantId): bool
    {
        // Only allowlisted tables reach here — `CustomFieldValues::usable()`
        // refuses anything else — but the table name still must not be trusted
        // enough to interpolate, so the lookup is a fixed map.
        $tenantColumn = match ($recordTable) {
            'tbluser' => 'sub_institute_id',
            default => null,
        };

        if ($tenantColumn === null) {
            return false;
        }

        return DB::table($recordTable)
            ->where('id', $recordId)
            ->where($tenantColumn, $tenantId)
            ->exists();
    }
}
