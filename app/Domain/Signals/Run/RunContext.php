<?php

namespace App\Domain\Signals\Run;

/**
 * Which research run the current process is working for, so a call made far below the researcher (an AI request in StructuredAi) can say whose it
 * is without every call site passing the run id down. Process-wide, like RunBudget, and for the same reason: the researcher is one long
 * synchronous method and its helpers are built independently of it.
 *
 * The researcher sets it once the run row exists and clears it in a `finally`, so a run that dies half way never leaves its id behind for the
 * next job a long-lived queue worker picks up. A call is attributed to the run only when it is made for the SAME organisation, because the cost
 * is later summed per organisation: a row filed under another organisation's id would be counted by nobody.
 */
final class RunContext
{
    /** The `ai_usage_events.related_type` of every AI call a research run makes. RunCost reads rows by this value. */
    public const RELATED_TYPE = 'g2g_research_run';

    private static ?int $runId = null;

    private static ?int $tenantId = null;

    public static function set(int $runId, int $tenantId): void
    {
        self::$runId = $runId;
        self::$tenantId = $tenantId;
    }

    public static function clear(): void
    {
        self::$runId = null;
        self::$tenantId = null;
    }

    /** @return array{run_id: int, tenant_id: int}|null null when no run is active */
    public static function current(): ?array
    {
        return self::$runId === null || self::$tenantId === null
            ? null
            : ['run_id' => self::$runId, 'tenant_id' => self::$tenantId];
    }
}
