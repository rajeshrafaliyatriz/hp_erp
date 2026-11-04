<?php

namespace App\Http\Controllers\Api\Outreach;

use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\DraftFactory;
use App\Domain\OutreachGate\GateResult;
use App\Domain\OutreachGate\OutreachGate;
use App\Domain\OutreachGate\OutreachGateAccess;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preview the outreach checks of one draft (slice 10): evaluate NOW, record the set, answer with every check in words. It reads and records; it sends nothing and
 * schedules nothing, and no part of this class can: the controller has no mailer, no queue and no transport.
 *
 * The message is looked up with the caller's organisation first, so another organisation's message and one that never existed are the same 404. The right is that of
 * the screen the message lives on (OutreachGateAccess); the route admits a holder of either, this narrows it to the table asked about.
 */
class OutreachGateController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/outreach/gate?table=gtm_outreach_messages|crm_emails&id=N[&latest=1] */
    public function show(Request $request, OutreachGate $gate, DraftFactory $drafts): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $rawTable = $request->query('table');
        $rawId = $request->query('id');
        $table = is_string($rawTable) ? $rawTable : '';
        $id = is_scalar($rawId) ? (string) $rawId : '';
        if (! in_array($table, Draft::TABLES, true) || ! ctype_digit($id) || (int) $id < 1) {
            return response()->json(['status' => 0, 'message' => 'Say which message: table (gtm_outreach_messages or crm_emails) and a numeric id.'], 422);
        }
        if (! OutreachGateAccess::canPreview($identity, $table)) {
            return response()->json(['status' => 0, 'message' => 'Your role does not have access to the checks of these messages.'], 403);
        }

        $tenant = $identity['sub_institute_id'];
        $draft = $drafts->find($tenant, $table, (int) $id);
        if ($draft === null) {
            return response()->json(['status' => 0, 'message' => 'Message not found.'], 404);
        }

        // `latest=1` reads the newest recorded set without running anything; without it the checks run now and the set is recorded.
        $result = $request->boolean('latest') ? $gate->latest($tenant, $table, $draft->id) : $gate->evaluate($draft);

        return response()->json(['status' => 1, 'data' => $this->present($table, $draft->id, $result)]);
    }

    /** @return array<string, mixed> */
    private function present(string $table, int $id, ?GateResult $result): array
    {
        $message = ['table' => $table, 'id' => $id];
        if ($result === null) {
            return ['message' => $message, 'passed' => null, 'checks' => [], 'checked_at' => null];
        }

        return ['message' => $message] + $result->toArray();
    }
}
