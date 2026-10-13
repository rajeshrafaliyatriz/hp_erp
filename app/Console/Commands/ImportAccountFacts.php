<?php

namespace App\Console\Commands;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpScorer;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the facts about each organisation (whatever THIS organisation's ICP scores: students, beds, fleet size, ...)
 * from a sheet, and scores every organisation again.
 *
 *   php artisan signals:import-account-facts facts.csv --tenant=6 --profile=scholar_k12 --dry-run
 *
 * Header row required. To find the organisation use `crm_ref` OR `name` (+ `city`). Control columns:
 *   board, tier (A|B|C: the entry offer, a person's judgement), flags (| separated; the ICP's manual flag names)
 * Every OTHER column is one of the organisation's own facts (a number if it looks like one, otherwise text). An empty
 * cell never erases what is already stored.
 *
 * A fact that fails one of the ICP's must-haves disqualifies the account and it is never searched again; a fact left
 * blank is never treated as a failure.
 */
class ImportAccountFacts extends Command
{
    protected $signature = 'signals:import-account-facts {file : CSV of account facts}
        {--tenant= : sub_institute_id}
        {--profile= : ICP key (the organisation\'s own ICP, else a template of that name)}
        {--pack= : same as --profile (older name)}
        {--dry-run : show what would change, write nothing}';

    protected $description = 'Add facts (students, fee, beds, ...) to target accounts and score them again against the ICP.';

    private const CONTROL = ['crm_ref', 'name', 'city', 'board', 'tier', 'flags'];
    private const LOWERCASE = ['management', 'medium'];

    public function handle(): int
    {
        $key = (string) ($this->option('profile') ?: $this->option('pack') ?: 'scholar_k12');
        $tenant = (int) $this->option('tenant');
        $pack = $this->resolvePack($tenant, $key);
        if ($pack === null || $tenant <= 0) {
            $this->error('Give --tenant and an ICP that exists (the organisation\'s own, or a template).');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $path = (string) $this->argument('file');
        if (! is_file($path) || ($h = fopen($path, 'r')) === false) {
            $this->error('Could not read the file.');

            return self::FAILURE;
        }
        $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), (array) fgetcsv($h));

        $scorer = new IcpScorer();
        $matched = 0;
        $unmatched = [];
        $byStatus = [];

        while (($line = fgetcsv($h)) !== false) {
            if ($line === [null] || count($line) < 2) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
            $account = $this->find($tenant, $key, $row);
            if ($account === null) {
                $unmatched[] = trim(($row['crm_ref'] ?? '') ?: (($row['name'] ?? '') . ' ' . ($row['city'] ?? '')));

                continue;
            }
            $matched++;

            $attr = (array) $account->attributes;
            foreach ($row as $col => $raw) {
                $v = trim((string) $raw);
                if ($v === '' || in_array($col, self::CONTROL, true) || ! preg_match('/^[a-z0-9_]{1,40}$/', (string) $col)) {
                    continue;
                }
                $num = str_replace([',', ' '], '', $v);
                $attr[$col] = is_numeric($num) ? (str_contains($num, '.') ? (float) $num : (int) $num) : (in_array($col, self::LOWERCASE, true) ? strtolower($v) : $v);
            }
            if (trim((string) ($row['tier'] ?? '')) !== '') {
                $attr['tier'] = strtoupper(trim((string) $row['tier']));
            }
            if (trim((string) ($row['flags'] ?? '')) !== '') {
                $attr['flags'] = array_values(array_unique(array_filter(array_map('trim', explode('|', (string) $row['flags'])))));
            }
            $board = strtoupper(trim((string) ($row['board'] ?? ''))) ?: $account->board;

            $score = $scorer->score([
                'name' => $account->name, 'city' => $account->city, 'state' => $account->state, 'board' => $board, 'level' => $account->level,
                'website' => $account->website, 'attributes' => $attr,
            ], $pack);
            $byStatus[$score['status']] = ($byStatus[$score['status']] ?? 0) + 1;

            if (! $dry) {
                $account->forceFill([
                    'attributes' => $attr, 'board' => $board, 'icp_status' => $score['status'], 'icp_tier' => null,
                    'icp_fit_pct' => $score['fit_pct'], 'icp_known_share' => $score['known_share'], 'icp_notes' => $score['notes'],
                    'watch_priority' => $score['watch_priority'],
                ])->save();
            }
        }
        fclose($h);

        $this->line(($dry ? 'DRY RUN, nothing written. ' : '') . "Accounts matched: {$matched}; not found on the list: " . count($unmatched));
        ksort($byStatus);
        $this->line('ICP status after scoring: ' . json_encode($byStatus));
        if ($unmatched !== []) {
            $this->line('Not found (check the crm_ref or name and city): ' . implode('; ', array_slice($unmatched, 0, 8)) . (count($unmatched) > 8 ? '; ...' : ''));
        }

        return self::SUCCESS;
    }

    /** @return ?array<string, mixed> the organisation's own ICP configuration, else the template of that name */
    private function resolvePack(int $tenant, string $key): ?array
    {
        if ($tenant > 0 && Schema::hasTable('g2g_icp_profiles')) {
            $p = IcpProfile::where('sub_institute_id', $tenant)->where('key', $key)->first();
            if ($p) {
                return (array) $p->config;
            }
        }
        $tpl = config("signals_icp.{$key}");

        return is_array($tpl) ? $tpl : null;
    }

    /** @param array<string, string> $row */
    private function find(int $tenant, string $key, array $row): ?TargetAccount
    {
        $q = TargetAccount::where('sub_institute_id', $tenant)->where('pack', $key);
        if (trim((string) ($row['crm_ref'] ?? '')) !== '') {
            return $q->where('crm_ref', trim((string) $row['crm_ref']))->first();
        }
        if (trim((string) ($row['name'] ?? '')) !== '') {
            $k = StructuredAi::normalise((string) $row['name']) . '|' . StructuredAi::normalise((string) ($row['city'] ?? ''));

            return $q->where('account_key', mb_substr($k, 0, 191))->first();
        }

        return null;
    }
}
