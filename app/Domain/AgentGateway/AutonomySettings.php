<?php

namespace App\Domain\AgentGateway;

use App\Domain\AgentGateway\Tools\Text;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * How much the agent may do alone, and the organisation's kill switch: what the Autonomy tab reads and what an administrator changes (S7b).
 *
 * THERE IS NO SECOND POLICY HERE. The levels are stored in g2g_agent_autonomy and read by AutonomyPolicy, exactly as before; this class only reads them for
 * a screen and writes them for an administrator. A level set here is the level the next run and the next Accept use, because they read the same row.
 *
 *   - ACTIONS ARE NOT CREATED HERE. The actions are the tools in code (ToolRegistry) that config/agent_actions.php lists; a key outside them is refused
 *     (404), and so is the kill switch's own key `*`, which has its own call.
 *   - A LEVEL EQUAL TO THE DEFAULT REMOVES THE ORGANISATION'S ROW instead of storing a copy of the default, so a later change of the default still
 *     reaches an organisation that never chose. "The default" is what applies with no row of the organisation's own: the shared row (organisation 0), if
 *     there is one, else config/agent_actions.php.
 *   - THE KILL SWITCH IS THE ORGANISATION'S OWN ROW, NEVER THE SHARED ONE. Row 0 switches the agent off for everybody and is not changeable from a screen:
 *     every write and delete here names the caller's organisation, and an organisation of 0 is refused.
 *   - EVERY CHANGE IS ITS OWN AUDIT AND ITS OWN EVENT, written in the same transaction as the change, so the setting and its trail are saved together or
 *     not at all: a row in g2g_agent_tool_calls (run 0: it belongs to no run) and an `agent.autonomy.changed` event whose key carries that audit row's id
 *     (a setting is changed many times; a key naming the setting would lose every change after the first). A request that changes nothing writes nothing.
 *   - COUNTS are the last 30 days of g2g_agent_proposals, one row per action the agent asked for: `proposed` all of them, `applied` the ones that were carried
 *     out (the agent alone, or accepted), `accepted` the part of those a person accepted, `dismissed`, `failed`. The evidence for raising a level (people
 *     accept nearly everything) or lowering it (they dismiss it).
 */
final class AutonomySettings
{
    /** How many days of proposals the counters look at. */
    public const WINDOW_DAYS = 30;

    /** The tool names of the audit rows a settings change writes. */
    public const TOOL_SET_LEVEL = 'set_autonomy';

    public const TOOL_SET_KILL_SWITCH = 'set_kill_switch';

    /** The longest reason an administrator may give. */
    public const MAX_REASON = 300;

    private const EMPTY_COUNTS = ['proposed' => 0, 'applied' => 0, 'accepted' => 0, 'dismissed' => 0, 'failed' => 0];

    public function __construct(private readonly AutonomyPolicy $policy, private readonly AgentEvents $events)
    {
    }

    /**
     * The actions an administrator can set: the tools in code that the config lists, in the config's order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $tools = ToolRegistry::names();

        return array_values(array_filter(array_map('strval', array_keys((array) config('agent_actions.actions', []))), fn (string $key) => in_array($key, $tools, true)));
    }

    /** @return array{on: bool, scope: ?string} `scope` is `organisation` (the organisation's own switch), `platform` (the shared one) or null */
    public function killSwitch(int $tenantId): array
    {
        $scope = $this->policy->killScope($tenantId);

        return ['on' => $scope !== null, 'scope' => $scope];
    }

    /**
     * Everything the Autonomy tab shows, for one organisation.
     *
     * @return array{kill_switch: array{on: bool, scope: ?string}, actions: list<array<string, mixed>>}
     */
    public function state(int $tenantId): array
    {
        $this->policy->forget();

        return ['kill_switch' => $this->killSwitch($tenantId), 'actions' => $this->describe($tenantId, $this->keys())];
    }

    /**
     * One action as the screen shows it.
     *
     * @return array<string, mixed>
     *
     * @throws AgentRefused 404 when the key is not an action
     */
    public function action(int $tenantId, string $key): array
    {
        if (! in_array($key, $this->keys(), true)) {
            throw new AgentRefused('unknown_action', 'There is no such action.', 404);
        }
        $this->policy->forget();

        return $this->describe($tenantId, [$key])[0];
    }

    /**
     * Sets one action's level for the caller's organisation. The caller has already been checked as an administrator.
     *
     * @return array<string, mixed> the action as it now stands
     *
     * @throws AgentRefused 404 for a key that is not an action, 422 for a level that is not a level, 403 for no organisation
     */
    public function setLevel(int $tenantId, int $userId, string $key, string $level, ?string $reason = null): array
    {
        $this->needsAnOrganisation($tenantId);
        if (! in_array($key, $this->keys(), true)) {
            throw new AgentRefused('unknown_action', 'There is no such action.', 404);
        }
        if (! in_array($level, (array) config('agent_actions.levels', []), true)) {
            throw new AgentRefused('unknown_level', 'That is not a level an action can be set to.', 422);
        }
        $note = $this->reason($reason);

        try {
            $this->changeLevel($tenantId, $userId, $key, $level, $note);
        } catch (UniqueConstraintViolationException) {
            // Two administrators saved the first setting of this action at once and the unique key let one in: the other now finds its row and updates it.
            $this->changeLevel($tenantId, $userId, $key, $level, $note);
        }

        return $this->action($tenantId, $key);
    }

    /**
     * Switches the agent off for the caller's organisation (`on` true) or back on (`on` false). Only the organisation's own row is ever written or removed.
     *
     * @return array{on: bool, scope: ?string} the switch as it now stands (the shared switch may still keep the agent off: then `scope` is `platform`)
     *
     * @throws AgentRefused 403 for no organisation
     */
    public function setKillSwitch(int $tenantId, int $userId, bool $on, ?string $reason = null): array
    {
        $this->needsAnOrganisation($tenantId);
        $note = $this->reason($reason);

        try {
            $this->changeKillSwitch($tenantId, $userId, $on, $note);
        } catch (UniqueConstraintViolationException) {
            $this->changeKillSwitch($tenantId, $userId, $on, $note);
        }
        $this->policy->forget();

        return $this->killSwitch($tenantId);
    }

    // ---- writing ----

    private function changeLevel(int $tenantId, int $userId, string $key, string $level, ?string $note): void
    {
        DB::transaction(function () use ($tenantId, $userId, $key, $level, $note) {
            $this->policy->forget();
            $row = DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->where('action_key', $key)->lockForUpdate()->first();
            $setting = $this->policy->setting($tenantId, $key);
            $now = now();

            if ($level === $setting['default']) {
                // Back to the default: the organisation keeps no row of its own.
                if ($row === null) {
                    return;
                }
                DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->where('id', $row->id)->delete();
                $rowId = (int) $row->id;
            } elseif ($row === null) {
                $rowId = (int) DB::table('g2g_agent_autonomy')->insertGetId(['sub_institute_id' => $tenantId, 'action_key' => $key, 'level' => $level, 'updated_by' => $userId, 'updated_at' => $now]);
            } elseif ($row->level === $level) {
                return;
            } else {
                DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->where('id', $row->id)->update(['level' => $level, 'updated_by' => $userId, 'updated_at' => $now]);
                $rowId = (int) $row->id;
            }

            $this->trail($tenantId, $userId, self::TOOL_SET_LEVEL, $key, $setting['level'], $level, $note, $rowId);
        });
    }

    private function changeKillSwitch(int $tenantId, int $userId, bool $on, ?string $note): void
    {
        DB::transaction(function () use ($tenantId, $userId, $on, $note) {
            // The organisation's own row ONLY. Never whereIn([0, tenant]): row 0 is the platform's switch and no screen may touch it.
            $row = DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->where('action_key', AutonomyPolicy::KILL_SWITCH)->lockForUpdate()->first();
            $wasOn = $row !== null && $row->level === AutonomyPolicy::OFF;
            if ($on === $wasOn) {
                return;
            }

            if (! $on) {
                DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->where('id', $row->id)->delete();
                $rowId = (int) $row->id;
            } elseif ($row === null) {
                $rowId = (int) DB::table('g2g_agent_autonomy')->insertGetId(['sub_institute_id' => $tenantId, 'action_key' => AutonomyPolicy::KILL_SWITCH, 'level' => AutonomyPolicy::OFF, 'updated_by' => $userId, 'updated_at' => now()]);
            } else {
                DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->where('id', $row->id)->update(['level' => AutonomyPolicy::OFF, 'updated_by' => $userId, 'updated_at' => now()]);
                $rowId = (int) $row->id;
            }

            $this->trail($tenantId, $userId, self::TOOL_SET_KILL_SWITCH, AutonomyPolicy::KILL_SWITCH, $wasOn ? 'stopped' : 'running', $on ? 'stopped' : 'running', $note, $rowId);
        });
    }

    /** The audit row and the event of one change. Both are written inside the transaction of the change. */
    private function trail(int $tenantId, int $userId, string $tool, string $key, string $from, string $to, ?string $note, int $rowId): void
    {
        $what = ['action' => $key, 'from' => $from, 'to' => $to] + ($note !== null ? ['reason' => $note] : []);
        $auditId = AgentAudit::forSettings($tenantId)->record($tool, $what + ['by' => $userId], 'applied', null, null, "g2g_agent_autonomy:{$rowId}");
        $this->events->autonomyChanged($tenantId, $rowId, $auditId, $userId, $what);
    }

    private function needsAnOrganisation(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new AgentRefused('no_organisation', 'There is no organisation to change this for. The setting shared by every organisation cannot be changed from a screen.', 403);
        }
    }

    private function reason(?string $reason): ?string
    {
        $text = mb_substr(Text::plain($reason, false), 0, self::MAX_REASON);

        return $text === '' ? null : $text;
    }

    // ---- reading ----

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function describe(int $tenantId, array $keys): array
    {
        $counts = $this->counts($tenantId, $keys);
        $changes = $this->lastChanges($tenantId, $keys);
        $presenter = new AgentPresenter($tenantId);
        $names = $presenter->userNames(array_column($changes, 'by'));
        $levels = array_values((array) config('agent_actions.levels', []));

        $out = [];
        foreach ($keys as $key) {
            $setting = $this->policy->setting($tenantId, $key);
            $change = $changes[$key] ?? null;
            $out[] = [
                'key' => $key,
                'label' => (string) config("agent_actions.labels.{$key}.label", $key),
                'description' => (string) config("agent_actions.labels.{$key}.description", ''),
                'default' => $setting['default'],
                'level' => $setting['level'],
                'overridden' => $setting['overridden'],
                'levels' => $levels,
                'counts' => $counts[$key],
                'last_changed' => $change === null ? null : ['at' => $presenter->iso($change['at']), 'by_name' => $change['by'] !== null ? ($names[$change['by']] ?? null) : null],
            ];
        }

        return $out;
    }

    /**
     * What the agent did with each action in the last 30 days, from the organisation's own proposals.
     *
     * @param  list<string>  $keys
     * @return array<string, array<string, int>>
     */
    private function counts(int $tenantId, array $keys): array
    {
        $out = array_fill_keys($keys, self::EMPTY_COUNTS);
        $rows = DB::table('g2g_agent_proposals')->where('sub_institute_id', $tenantId)->whereIn('action', $keys)->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->selectRaw('action, status, applied_via, COUNT(*) as n')->groupBy('action', 'status', 'applied_via')->get();

        foreach ($rows as $row) {
            $action = (string) $row->action;
            $n = (int) $row->n;
            $out[$action]['proposed'] += $n;
            match ((string) $row->status) {
                'applied' => $this->countApplied($out[$action], (string) $row->applied_via, $n),
                'dismissed' => $out[$action]['dismissed'] += $n,
                'failed' => $out[$action]['failed'] += $n,
                default => null,   // pending: proposed, not yet decided
            };
        }

        return $out;
    }

    /** @param array<string, int> $counts */
    private function countApplied(array &$counts, string $via, int $n): void
    {
        $counts['applied'] += $n;
        if ($via === 'user') {
            $counts['accepted'] += $n;
        }
    }

    /**
     * Who last changed each action, and when: the newest of the organisation's `agent.autonomy.changed` events (it also covers a level put back to its
     * default, when no row is left) and the setting's own row (a change made by hand has no event).
     *
     * @param  list<string>  $keys
     * @return array<string, array{at: Carbon, by: ?int}>
     */
    private function lastChanges(int $tenantId, array $keys): array
    {
        $latest = [];
        $events = DB::table('g2g_event')->where('sub_institute_id', $tenantId)->where('type', AgentEvents::AUTONOMY_CHANGED)->orderByDesc('id')->limit(500)->get(['payload', 'actor_id', 'occurred_at']);
        foreach ($events as $event) {
            $payload = json_decode((string) $event->payload, true);
            $key = is_array($payload) ? ($payload['action'] ?? null) : null;
            if (! is_string($key) || ! in_array($key, $keys, true) || isset($latest[$key])) {
                continue;
            }
            $latest[$key] = ['at' => Carbon::parse($event->occurred_at), 'by' => $event->actor_id !== null ? (int) $event->actor_id : null];
        }

        $rows = DB::table('g2g_agent_autonomy')->where('sub_institute_id', $tenantId)->whereIn('action_key', $keys)->whereNotNull('updated_at')->get(['action_key', 'updated_by', 'updated_at']);
        foreach ($rows as $row) {
            $at = Carbon::parse($row->updated_at);
            $key = (string) $row->action_key;
            if (! isset($latest[$key]) || $at->gt($latest[$key]['at'])) {
                $latest[$key] = ['at' => $at, 'by' => $row->updated_by !== null ? (int) $row->updated_by : null];
            }
        }

        return $latest;
    }
}
