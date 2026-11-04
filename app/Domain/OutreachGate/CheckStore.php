<?php

namespace App\Domain\OutreachGate;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The record of what the gate checked (table g2g_outreach_gate_checks): append-only, one row per check, grouped by the run id of the evaluation. The NEWEST set of a
 * message (the run of its highest row id) is its current one; a re-check adds a set and never edits an old one. Every read and write carries the organisation.
 *
 * Storing is a RECORD, never a decision: a failure here (a host without the table, a full disk) is reported to the caller as `false` and never changes whether a
 * message passed.
 */
final class CheckStore
{
    public function save(GateResult $result): bool
    {
        if ($result->checks === [] || $result->tenant <= 0 || ! Schema::hasTable('g2g_outreach_gate_checks')) {
            return false;
        }

        $at = $result->checkedAt->toDateTimeString();
        DB::table('g2g_outreach_gate_checks')->insert(array_map(fn (CheckResult $c) => [
            'sub_institute_id' => $result->tenant, 'message_id' => $result->messageId, 'message_table' => $result->table, 'run_id' => $result->runId,
            'check_key' => mb_substr($c->key, 0, 40), 'passed' => $c->passed ? 1 : 0, 'detail' => mb_substr($c->detail, 0, CheckResult::DETAIL_MAX), 'checked_at' => $at, 'created_at' => $at,
        ], $result->checks));

        return true;
    }

    /** The newest set of a message, or null when it was never evaluated. The labels are not stored; they come from the check list that wrote the keys. @param array<string, string> $labels key => label */
    public function latest(int $tenant, string $table, int $messageId, array $labels = []): ?GateResult
    {
        if ($tenant <= 0 || $messageId <= 0 || ! Schema::hasTable('g2g_outreach_gate_checks')) {
            return null;
        }

        $base = fn () => DB::table('g2g_outreach_gate_checks')->where('sub_institute_id', $tenant)->where('message_table', $table)->where('message_id', $messageId);
        $runId = $base()->orderByDesc('id')->value('run_id');
        if ($runId === null) {
            return null;
        }

        $rows = $base()->where('run_id', $runId)->orderBy('id')->get();

        return new GateResult(
            (string) $runId,
            $table,
            $messageId,
            $tenant,
            $rows->map(fn ($r) => new CheckResult((string) $r->check_key, $labels[$r->check_key] ?? (string) $r->check_key, (bool) $r->passed, (string) $r->detail))->all(),
            Carbon::parse((string) $rows->first()->checked_at),
        );
    }
}
