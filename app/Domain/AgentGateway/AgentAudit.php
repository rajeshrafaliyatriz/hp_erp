<?php

namespace App\Domain\AgentGateway;

use Illuminate\Support\Facades\DB;

/**
 * The audit trail of one run: a row in g2g_agent_tool_calls for every read the gateway made and every action the model asked for, whatever the verdict
 * (applied, proposed, denied, error). A refusal is a row too, with its reason, so "what did the model try" is answerable.
 *
 * Append only. The arguments are stored as JSON text cut to a length cap; the hash is of the whole of them.
 */
final class AgentAudit
{
    public const MAX_ARGS = 4000;

    private int $seq = 0;

    public function __construct(private readonly int $tenantId, private readonly int $runId)
    {
    }

    public function count(): int
    {
        return $this->seq;
    }

    /** @param array<string, mixed>|string|null $args */
    public function record(string $tool, array|string|null $args, string $outcome, ?string $denyReason = null, ?int $proposalId = null, ?string $resultRef = null, ?int $latencyMs = null): void
    {
        $this->seq++;
        $json = is_string($args) ? $args : (string) json_encode($args ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        DB::table('g2g_agent_tool_calls')->insert([
            'sub_institute_id' => $this->tenantId, 'run_id' => $this->runId, 'seq' => $this->seq,
            // The name is kept as the model wrote it (cut), so a refused tool is visible; it was never executed.
            'tool' => mb_substr($tool, 0, 60), 'args_hash' => sha1($json), 'args' => mb_substr($json, 0, self::MAX_ARGS),
            'outcome' => $outcome, 'deny_reason' => $denyReason !== null ? mb_substr($denyReason, 0, 60) : null,
            'proposal_id' => $proposalId, 'result_ref' => $resultRef !== null ? mb_substr($resultRef, 0, 80) : null,
            'latency_ms' => $latencyMs, 'created_at' => now(),
        ]);
    }
}
