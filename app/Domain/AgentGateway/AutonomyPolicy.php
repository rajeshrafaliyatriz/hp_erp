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
        return $this->killScope($tenantId) !== null;
    }

    /**
     * WHICH switch is off, for the screen that offers the organisation's own: `platform` (the shared row, organisation 0: nothing on a screen can change
     * it, and it wins when both are set, because the organisation's own switch cannot bring the agent back while it is on), `organisation` (this
     * organisation's own row) or null (the agent is not switched off).
     */
    public function killScope(int $tenantId): ?string
    {
        $rows = $this->load($tenantId);
        if (($rows[0][self::KILL_SWITCH] ?? null) === self::OFF) {
            return 'platform';
        }

        return ($rows[$tenantId][self::KILL_SWITCH] ?? null) === self::OFF ? 'organisation' : null;
    }

    /** The level of one action for one organisation: one of off, suggest, draft, approval, alone. */
    public function level(int $tenantId, string $action): string
    {
        if (! in_array($action, ToolRegistry::names(), true) || $this->killed($tenantId)) {
            return self::OFF;
        }

        return $this->setting($tenantId, $action)['level'];
    }

    /**
     * What is SET for one action, whatever the kill switch says (the settings screen shows the setting, not the switch): the level in force, the level that
     * would be in force with no setting of the organisation's own (`default`: the shared row for everybody if there is one, else config/agent_actions.php)
     * and whether the organisation has a setting of its own. An action that is not a tool, and a stored level that is not a level, read as `off`: it fails closed.
     *
     * @return array{level: string, default: string, overridden: bool}
     */
    public function setting(int $tenantId, string $action): array
    {
        if (! in_array($action, ToolRegistry::names(), true)) {
            return ['level' => self::OFF, 'default' => self::OFF, 'overridden' => false];
        }
        $levels = (array) config('agent_actions.levels', []);
        $rows = $this->load($tenantId);
        $valid = fn (?string $level): string => $level !== null && in_array($level, $levels, true) ? $level : self::OFF;

        $default = $valid($rows[0][$action] ?? (string) config("agent_actions.actions.{$action}", self::OFF));
        // The organisation's own setting wins over the shared one, which wins over the default.
        $own = $tenantId > 0 && array_key_exists($action, $rows[$tenantId]);

        return ['level' => $own ? $valid($rows[$tenantId][$action]) : $default, 'default' => $default, 'overridden' => $own];
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
