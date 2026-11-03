<?php

namespace App\Domain\AgentGateway;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much the agent may do without asking, for one organisation (slice 7, thin). Defaults come from config/agent_actions.php; a row in
 * g2g_agent_autonomy overrides one action for one organisation; a row with action_key '*' and level 'off' is the kill switch, and one for organisation 0
 * switches the agent off for everybody.
 *
 * FAILS CLOSED. An action that is not in the registry is `off` whatever any row says. A stored level that is not one of the known levels is `off`. A
 * missing table (a host that has not run the migration) leaves the defaults in force, because the kill switch cannot have been set there; every other
 * answer here is the safer one.
 */
final class AutonomyPolicy
{
    public const OFF = 'off';

    public const ALONE = 'alone';

    public const KILL_SWITCH = '*';

    /** @var array<string, array<string, string>> tenant => action_key => level */
    private array $rows = [];

    /** True when the agent is switched off for this organisation (its own row, or the shared one for everybody). */
    public function killed(int $tenantId): bool
    {
        $rows = $this->load($tenantId);

        return ($rows[$tenantId][self::KILL_SWITCH] ?? null) === self::OFF || ($rows[0][self::KILL_SWITCH] ?? null) === self::OFF;
    }

    /** The level of one action for one organisation: one of off, suggest, draft, approval, alone. */
    public function level(int $tenantId, string $action): string
    {
        if (! in_array($action, ToolRegistry::names(), true) || $this->killed($tenantId)) {
            return self::OFF;
        }
        $levels = (array) config('agent_actions.levels', []);
        $rows = $this->load($tenantId);
        // The organisation's own setting wins over the shared one, which wins over the default.
        $stored = $rows[$tenantId][$action] ?? $rows[0][$action] ?? null;
        $level = $stored ?? (string) config("agent_actions.actions.{$action}", self::OFF);

        return in_array($level, $levels, true) ? $level : self::OFF;
    }

    /** Forgets what was read, so a setting changed during a long-lived process is read again. */
    public function forget(): void
    {
        $this->rows = [];
    }

    /** @return array<int, array<string, string>> */
    private function load(int $tenantId): array
    {
        if (! isset($this->rows[$tenantId])) {
            $found = [0 => [], $tenantId => []];
            if (Schema::hasTable('g2g_agent_autonomy')) {
                foreach (DB::table('g2g_agent_autonomy')->whereIn('sub_institute_id', [0, $tenantId])->get(['sub_institute_id', 'action_key', 'level']) as $row) {
                    $found[(int) $row->sub_institute_id][(string) $row->action_key] = (string) $row->level;
                }
            }
            $this->rows[$tenantId] = $found;
        }

        return $this->rows[$tenantId];
    }
}
