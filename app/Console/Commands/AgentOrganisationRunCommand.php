<?php

namespace App\Console\Commands;

use App\Domain\AgentGateway\AgentPresenter;
use App\Domain\AgentGateway\AgentRefused;
use App\Domain\AgentGateway\OrgAgentService;
use App\Domain\OrgAccounts\OrgAccount;
use App\Support\RoleKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the Organisation agent ONCE for one organisation and prints what it did (slice 7, thin of the agentic CRM): the brief, every proposal with what became
 * of it, the audit trail, the model, the tokens and the cost in the currency the model was priced in. It is the same service the Run agent button calls
 * (OrgAgentService), so it writes only through the gateway: the notes and tasks the organisation's autonomy settings allow are applied, as the person it
 * acts for; everything else waits as a proposal. It cannot send anything: the agent has no tool that can.
 *
 * FOR DEVELOPMENT DATABASES. The live connection is refused outright (live needs the owner's explicit go, and a real model call costs money).
 *
 *   php artisan agent:organisation-run --tenant=6 --org-account=1
 *   php artisan agent:organisation-run --tenant=6 --org-account=1 --user=28
 *
 * Exit code 0 when the run completed; 1 when it did not (a refusal before the run, an unavailable model, a failed run), with the reason printed.
 */
class AgentOrganisationRunCommand extends Command
{
    /** Connections this command will never use, however it is asked. */
    private const REFUSED_CONNECTIONS = ['live'];

    protected $signature = 'agent:organisation-run
        {--tenant= : The organisation (sub_institute_id) the run is for. Required}
        {--org-account= : The Organisation 360 identity (g2g_org_accounts.id) to run the agent on. Required}
        {--user= : The person the agent acts for (a user id of that organisation). Default: the identity\'s owner, else the first active administrator}
        {--database= : Connection name; the default connection when omitted. The live connection is refused}';

    protected $description = 'Run the Organisation agent once for one organisation and print the brief, the proposals and the cost. Development databases only.';

    public function handle(): int
    {
        $tenant = $this->wholeNumber('tenant');
        $identity = $this->wholeNumber('org-account');
        if ($tenant === null || $identity === null) {
            $this->error('--tenant and --org-account are both required and must be whole numbers above 0.');

            return self::INVALID;
        }

        $previous = DB::getDefaultConnection();
        try {
            $database = trim((string) $this->option('database'));
            if ($database !== '') {
                if (in_array(strtolower($database), self::REFUSED_CONNECTIONS, true)) {
                    $this->error("The '{$database}' connection is refused: this command is for development databases. A live run needs the owner's explicit go.");

                    return self::INVALID;
                }
                if (config('database.connections.' . $database) === null) {
                    $this->error("There is no database connection called '{$database}'.");

                    return self::INVALID;
                }
                DB::setDefaultConnection($database);
            }
            if (in_array(strtolower(DB::getDefaultConnection()), self::REFUSED_CONNECTIONS, true)) {
                $this->error('The default connection is the live one: this command is for development databases.');

                return self::INVALID;
            }

            return $this->runOnce($tenant, $identity);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function runOnce(int $tenant, int $identity): int
    {
        if (! Schema::hasTable('g2g_agent_runs') || ! Schema::hasTable('g2g_agent_proposals') || ! Schema::hasTable('g2g_org_accounts')) {
            $this->error('This database has no agent tables yet (g2g_agent_runs, g2g_agent_proposals) or no Organisation 360 table. Run the migrations 2026_12_10_090000 to 090400 first.');

            return self::FAILURE;
        }
        $account = OrgAccount::query()->where('sub_institute_id', $tenant)->find($identity);
        if ($account === null) {
            $this->error("Organisation {$tenant} has no Organisation 360 identity {$identity}.");

            return self::FAILURE;
        }
        $userId = $this->actingUser($tenant, $account);
        if ($userId === null) {
            return self::FAILURE;
        }

        $this->line('Connection: ' . DB::getDefaultConnection() . ' | organisation ' . $tenant . ' | identity ' . $account->id . ' "' . $account->name . '" | acting for user ' . $userId);

        try {
            $run = app(OrgAgentService::class)->run($tenant, $userId, (int) $account->id, 'cli');
        } catch (AgentRefused $e) {
            $this->error("Refused before a run was made [{$e->reason}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $detail = (new AgentPresenter($tenant))->runDetail($run);
        $this->report($detail);

        return $detail['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $d AgentPresenter::runDetail() */
    private function report(array $d): void
    {
        $this->line('');
        $this->line("Run #{$d['id']}: " . strtoupper((string) $d['status']) . ($d['error_code'] ? " [{$d['error_code']}]" : ''));
        if ($d['message']) {
            $this->line('  ' . $d['message']);
        }
        $this->line('Model: ' . ($d['provider'] ?? '-') . ' / ' . ($d['model'] ?? '-'));
        $cost = $d['cost'] === null ? 'unknown (no price for what was used)' : $d['cost_currency'] . ' ' . rtrim(rtrim(number_format((float) $d['cost'], 4, '.', ''), '0'), '.');
        $this->line('Tokens: in ' . ($d['input_tokens'] ?? '-') . ', out ' . ($d['output_tokens'] ?? '-') . ' | Cost: ' . $cost);
        $this->line('Started ' . ($d['started_at'] ?? '-') . ', finished ' . ($d['finished_at'] ?? '-'));

        $this->line('');
        $this->line('BRIEF');
        $this->line($d['brief'] !== null ? '  ' . str_replace("\n", "\n  ", (string) $d['brief']) : '  (no brief: the model gave none, or one that cited records it was not shown)');
        foreach ($d['brief_evidence'] as $ref) {
            $this->line('    cites ' . $ref['type'] . ':' . $ref['id'] . ' ' . $ref['label']);
        }

        $this->line('');
        $this->line('PROPOSALS (' . count($d['proposals']) . ')');
        if ($d['proposals'] === []) {
            $this->line('  (none)');
        }
        foreach ($d['proposals'] as $p) {
            $how = match (true) {
                $p['status'] === 'applied' && $p['applied_via'] === 'policy' => 'APPLIED by the agent, alone',
                $p['status'] === 'applied' => 'applied after a person accepted it',
                $p['status'] === 'pending' => 'WAITING for a person',
                default => strtoupper((string) $p['status']) . ($p['error'] ? ': ' . $p['error'] : ''),
            };
            $this->line("  #{$p['id']} {$p['action']} | {$how}" . ($p['result_ref'] ? " | wrote {$p['result_ref']}" : ''));
            $this->line('      ' . $p['summary']);
            if ($p['reason']) {
                $this->line('      why: ' . $p['reason']);
            }
            foreach ($p['evidence'] as $ref) {
                $this->line('      cites ' . $ref['type'] . ':' . $ref['id'] . ' ' . $ref['label']);
            }
        }

        $this->line('');
        $this->line('TRAIL (' . count($d['trace']) . ' steps)');
        foreach ($d['trace'] as $t) {
            $this->line("  {$t['seq']}. {$t['tool']} -> {$t['outcome']}" . ($t['deny_reason'] ? " ({$t['deny_reason']})" : '') . ($t['result_ref'] ? " {$t['result_ref']}" : ''));
        }
    }

    /** The person the agent acts for: the one asked for, else the identity's owner, else the first active administrator. Null after an error was printed. */
    private function actingUser(int $tenant, OrgAccount $account): ?int
    {
        $active = fn () => DB::table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1);

        $asked = $this->option('user');
        if ($asked !== null && $asked !== '') {
            if (! ctype_digit((string) $asked) || (int) $asked <= 0 || ! $active()->where('id', (int) $asked)->exists()) {
                $this->error("--user {$asked} is not an active user of organisation {$tenant}.");

                return null;
            }

            return (int) $asked;
        }

        if ($account->owner_user_id !== null && $active()->where('id', (int) $account->owner_user_id)->exists()) {
            return (int) $account->owner_user_id;
        }

        $administrators = DB::table('tbluserprofilemaster')->where('sub_institute_id', $tenant)->get(['id', 'sub_institute_id', 'role_key', 'name'])
            ->filter(fn ($p) => RoleKey::fromProfile($p) === 'administrator')->pluck('id')->all();
        $first = $administrators === [] ? null : $active()->whereIn('user_profile_id', $administrators)->orderBy('id')->value('id');
        if ($first === null) {
            $this->error("Organisation {$tenant} has no active administrator to act for: pass --user=<id>.");

            return null;
        }

        return (int) $first;
    }

    private function wholeNumber(string $option): ?int
    {
        $value = $this->option($option);

        return (is_string($value) || is_int($value)) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }
}
