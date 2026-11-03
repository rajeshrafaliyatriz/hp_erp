<?php

namespace App\Http\Controllers\Api\Facts;

use App\Domain\Facts\ConsentGate;
use App\Domain\Facts\FactEntities;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The consent line of a record (slice 4): what the ConsentGate says for each channel and which signals it found. Read only; routes and rights in routes/facts.php
 * (the right of the screen the record belongs to). The record is looked up with the caller's organisation first, so another organisation's id and one that
 * never existed are the same 404, and the gate is then asked about that organisation only.
 */
class ConsentController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/consent/{contact|organization|lead}/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        $type = (string) $request->route('entityType');
        if (FactEntities::find($t, $type, $id) === null) {
            return response()->json(['status' => 0, 'message' => 'Record not found.'], 404);
        }

        $report = app(ConsentGate::class)->report($type, $id, $t);

        return response()->json(['status' => 1, 'data' => ['channels' => $report['channels'], 'sources' => $report['sources']]]);
    }
}
