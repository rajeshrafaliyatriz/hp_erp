<?php

namespace App\Console\Commands;

use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpScorer;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Loads the organisations an ICP should watch from a CSV into g2g_target_accounts, scored against that ICP.
 *
 *   php artisan signals:import-accounts leads.csv --tenant=6 --profile=scholar_k12 --dry-run     (nothing written)
 *   php artisan signals:import-accounts list.csv  --tenant=9 --profile=hospitals --preset=generic \
 *        --map="name=Hospital;city=City;state=State;attr.beds=Beds;attr.nabh=NABH"
 *
 * Presets:
 *   vtiger   a Vtiger "Leads" export: the name is in `Company` for some rows and in `Last Name` for others (with
 *            `Company` empty and `Designation` holding the board). This is the default.
 *   generic  any CSV: say which column is what with --map. Keys: name (required), city, state, board, level, website,
 *            crm_ref, crm_owner, contact_name; and attr.<anything>=<column> for the organisation's own facts.
 *
 * The ICP is the organisation's own (a database profile with that key), else the template of that name. Contact
 * phone numbers and emails are never imported: `crm_ref` lets a person find the contact in the CRM. Importing again
 * updates the same organisations; it never creates a second row for one.
 */
class ImportTargetAccounts extends Command
{
    protected $signature = 'signals:import-accounts {file : CSV to import}
        {--tenant= : sub_institute_id the accounts belong to}
        {--profile= : ICP key (the organisation\'s own ICP, else a template of that name)}
        {--pack= : same as --profile (older name)}
        {--preset=vtiger : vtiger | generic}
        {--map= : generic preset: "name=Col;city=Col;attr.students=Col"}
        {--dry-run : read and score the file, write nothing}
        {--limit= : only the first N rows}';

    protected $description = 'Import a CSV of organisations as watched target accounts, scored against an ICP.';

    private const BOARDS = ['GSEB', 'CBSE', 'ICSE', 'IB', 'CAMBRIDGE'];
    private const MAPPABLE = ['name', 'city', 'state', 'board', 'level', 'website', 'crm_ref', 'crm_owner', 'contact_name'];

    public function handle(): int
    {
        $key = (string) ($this->option('profile') ?: $this->option('pack') ?: 'scholar_k12');
        $dry = (bool) $this->option('dry-run');
        $tenant = (int) $this->option('tenant');
        if (! $dry && $tenant <= 0) {
            $this->error('--tenant is required unless --dry-run.');

            return self::FAILURE;
        }
        [$pack, $profileId] = $this->resolveIcp($tenant, $key);
        if ($pack === null) {
            $this->error("No ICP '{$key}' (neither this organisation's own nor a template).");

            return self::FAILURE;
        }
        $preset = (string) $this->option('preset');
        $map = $this->parseMap((string) $this->option('map'));
        if ($preset === 'generic' && ! isset($map['name'])) {
            $this->error('The generic preset needs --map with at least name=<column>.');

            return self::FAILURE;
        }

        $rows = $this->readCsv((string) $this->argument('file'));
        if ($rows === null) {
            $this->error('Could not read the file.');

            return self::FAILURE;
        }
        if ($this->option('limit')) {
            $rows = array_slice($rows, 0, (int) $this->option('limit'));
        }

        $scorer = new IcpScorer();
        $seen = [];
        $stats = ['rows' => count($rows), 'no_name' => 0, 'duplicate_in_file' => 0, 'created' => 0, 'updated' => 0];
        $byStatus = [];
        $byPriority = [];
        $disq = [];
        $notImported = [];
        $watch = [];
        $first = [];

        foreach ($rows as $n => $r) {
            $line = $n + 2;   // the file's own line number: row 1 is the header
            $acc = $preset === 'generic' ? $this->genericAccount($r, $map) : $this->vtigerAccount($r);
            if ($acc === null) {
                $stats['no_name']++;
                $notImported[] = ['line' => $line, 'ref' => $this->refOf($r), 'what' => '(no school name)', 'why' => 'no name in the columns that carry one'];

                continue;
            }
            if (isset($seen[$acc['account_key']])) {
                $stats['duplicate_in_file']++;
                $notImported[] = ['line' => $line, 'ref' => $acc['crm_ref'], 'what' => $acc['name'], 'why' => 'repeats line ' . $first[$acc['account_key']] . ' (same name and city); the first is kept'];

                continue;
            }
            $seen[$acc['account_key']] = true;
            $first[$acc['account_key']] = $line;

            $score = $scorer->score($acc, $pack);
            $byStatus[$score['status']] = ($byStatus[$score['status']] ?? 0) + 1;
            $byPriority[$score['watch_priority']] = ($byPriority[$score['watch_priority']] ?? 0) + 1;
            if ($score['status'] === 'disqualified') {
                $disq[] = ['line' => $line, 'ref' => $acc['crm_ref'], 'what' => $acc['name'], 'why' => $score['notes']['disqualified_by']];
            }
            if ($score['status'] === 'watch') {
                $watch[] = ['line' => $line, 'ref' => $acc['crm_ref'], 'what' => $acc['name'], 'why' => $score['notes']['watch']['reason']];
            }
            if ($dry) {
                continue;
            }

            $values = [
                'name' => $acc['name'], 'city' => $acc['city'], 'state' => $acc['state'], 'board' => $acc['board'], 'level' => $acc['level'],
                'website' => $acc['website'], 'crm_ref' => $acc['crm_ref'], 'crm_owner' => $acc['crm_owner'], 'contact_name' => $acc['contact_name'],
                'icp_status' => $score['status'], 'icp_tier' => null, 'icp_fit_pct' => $score['fit_pct'],
                'icp_known_share' => $score['known_share'], 'icp_notes' => $score['notes'], 'watch_priority' => $score['watch_priority'],
            ];
            if ($profileId !== null) {
                $values['icp_profile_id'] = $profileId;
            }
            $row = TargetAccount::where(['sub_institute_id' => $tenant, 'pack' => $key, 'account_key' => $acc['account_key']])->first();
            if ($row) {
                // Keep facts a person added by hand and the watch history; only the file's own fields and the score change.
                $row->forceFill($values + ['attributes' => array_merge((array) $row->attributes, $acc['attributes'])])->save();
                $stats['updated']++;
            } else {
                TargetAccount::create($values + ['attributes' => $acc['attributes'] ?: null, 'sub_institute_id' => $tenant, 'pack' => $key, 'account_key' => $acc['account_key']]);
                $stats['created']++;
            }
        }

        $this->line(($dry ? 'DRY RUN, nothing written. ' : '') . 'Organisations read: ' . count($seen) . ' of ' . $stats['rows'] . ' rows'
            . " (no name: {$stats['no_name']}, repeated in file: {$stats['duplicate_in_file']})");
        if (! $dry) {
            $this->line("Created {$stats['created']}, updated {$stats['updated']}.");
        }
        ksort($byStatus);
        ksort($byPriority);
        $this->line('ICP status: ' . json_encode($byStatus));
        $this->line('Watch priority (1 = watch first, 9 = never): ' . json_encode($byPriority));
        $this->mappingReport($preset, $map);
        $this->rowReport('Rows NOT imported (' . count($notImported) . ')', $notImported);
        $this->rowReport('Imported but outside the ICP, so never searched (' . count($disq) . ')', $disq);
        $this->rowReport('Kept on WATCH (' . count($watch) . ')', $watch);
        $needs = implode(', ', array_map(fn ($c) => $c['label'] ?? $c['key'], (array) ($pack['criteria'] ?? [])));
        $this->line("Facts this ICP scores that may be missing from the file: {$needs}. Accounts stay \"unscored\" until they are added (signals:import-account-facts).");

        return self::SUCCESS;
    }

    private function refOf(array $r): ?string
    {
        $ref = trim((string) ($r['Lead Number'] ?? ''));

        return $ref !== '' ? $ref : null;
    }

    /** Which CSV column fed which field, and which columns were deliberately left out. @param array<string, string> $map */
    private function mappingReport(string $preset, array $map): void
    {
        $this->line('');
        $this->line("Column mapping ({$preset} preset):");
        if ($preset === 'generic') {
            foreach ($map as $field => $col) {
                $this->line(sprintf('  %-28s -> %s', $col, $field));
            }

            return;
        }
        foreach ([
            'Lead Number' => 'crm_ref (the CRM lead number)',
            'Company, else Last Name' => 'name (the school)',
            'Last Name (only when Company is filled)' => 'contact_name',
            'City / State' => 'city / state',
            'Designation' => 'board (kept only if GSEB, CBSE, ICSE, IB or CAMBRIDGE)',
            'Description' => 'level (kept only when the text contains "level")',
            'Website' => 'website',
            'Assigned To' => 'crm_owner',
        ] as $col => $field) {
            $this->line(sprintf('  %-40s -> %s', $col, $field));
        }
        $this->line('  NOT imported on purpose: Primary Phone, Mobile Phone, Primary Email, Secondary Email, Fax, and every other column.');
    }

    /** @param list<array{line: int, ref: ?string, what: string, why: string}> $rows */
    private function rowReport(string $title, array $rows): void
    {
        $this->line('');
        $this->line($title . ($rows === [] ? ': none' : ':'));
        foreach (array_slice($rows, 0, 40) as $r) {
            $this->line(sprintf('  line %-4d %-10s %-44s %s', $r['line'], $r['ref'] ?? '-', mb_substr($r['what'], 0, 44), $r['why']));
        }
        if (count($rows) > 40) {
            $this->line('  ... and ' . (count($rows) - 40) . ' more');
        }
    }

    /** @return array{0: ?array<string, mixed>, 1: ?int} the ICP configuration and the profile id (null for a template) */
    private function resolveIcp(int $tenant, string $key): array
    {
        if ($tenant > 0 && Schema::hasTable('g2g_icp_profiles')) {
            $p = IcpProfile::where('sub_institute_id', $tenant)->where('key', $key)->first();
            if ($p) {
                return [(array) $p->config, (int) $p->id];
            }
        }
        $tpl = config("signals_icp.{$key}");

        return [is_array($tpl) ? $tpl : null, null];
    }

    /** @return array<string, string> account field => CSV column */
    private function parseMap(string $spec): array
    {
        $map = [];
        foreach (preg_split('/[;,]/', $spec) ?: [] as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$field, $col] = array_map('trim', explode('=', $pair, 2));
            if ($col !== '' && (in_array($field, self::MAPPABLE, true) || preg_match('/^attr\.[a-z0-9_]{1,40}$/i', $field))) {
                $map[$field] = $col;
            }
        }

        return $map;
    }

    /** @return list<array<string, string>>|null */
    private function readCsv(string $path): ?array
    {
        if (! is_file($path) || ($h = fopen($path, 'r')) === false) {
            return null;
        }
        $header = fgetcsv($h);
        if (! is_array($header)) {
            return null;
        }
        $header = array_map(fn ($c) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $header);
        $out = [];
        while (($line = fgetcsv($h)) !== false) {
            if ($line === [null] || count($line) < 2) {
                continue;
            }
            $line = array_map(fn ($v) => is_string($v) && ! mb_check_encoding($v, 'UTF-8') ? mb_convert_encoding($v, 'UTF-8', 'Windows-1252') : (string) $v, $line);
            $out[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }
        fclose($h);

        return $out;
    }

    /** Any CSV, columns named by --map. @param array<string, string> $r @param array<string, string> $map */
    private function genericAccount(array $r, array $map): ?array
    {
        $get = fn (string $field) => trim((string) ($r[$map[$field] ?? ''] ?? ''));
        $name = trim(preg_replace('/\s+/', ' ', $get('name')) ?? '');
        if ($name === '') {
            return null;
        }
        $attributes = [];
        foreach ($map as $field => $col) {
            if (str_starts_with($field, 'attr.')) {
                $v = trim((string) ($r[$col] ?? ''));
                if ($v !== '') {
                    $num = str_replace([',', ' '], '', $v);
                    $attributes[substr($field, 5)] = is_numeric($num) ? (str_contains($num, '.') ? (float) $num : (int) $num) : $v;
                }
            }
        }

        return $this->build($name, $get('city'), $get('state'), strtoupper($get('board')), $get('level'), $get('website'),
            $get('crm_ref'), $get('crm_owner'), $get('contact_name'), $attributes, false);
    }

    /** A Vtiger "Leads" row. @param array<string, string> $r */
    private function vtigerAccount(array $r): ?array
    {
        $company = trim((string) ($r['Company'] ?? ''));
        $last = trim((string) ($r['Last Name'] ?? ''));
        $name = trim(preg_replace('/\s+/', ' ', $company !== '' ? $company : $last) ?? '');
        if ($name === '') {
            return null;
        }
        $level = trim((string) ($r['Description'] ?? ''));
        // When the school is in Company, Last Name is a person. When it is in Last Name there is no named contact.
        $contact = $company !== '' && $last !== '' ? $last : '';

        return $this->build($name, (string) ($r['City'] ?? ''), (string) ($r['State'] ?? ''), strtoupper(trim((string) ($r['Designation'] ?? ''))),
            preg_match('/level/i', $level) === 1 ? $level : '', (string) ($r['Website'] ?? ''), (string) ($r['Lead Number'] ?? ''),
            (string) ($r['Assigned To'] ?? ''), $contact, [], true);
    }

    /** @param array<string, mixed> $attributes */
    private function build(string $name, string $city, string $state, string $board, string $level, string $website, string $crmRef, string $owner, string $contact, array $attributes, bool $boardMustBeKnown): array
    {
        if ($name === mb_strtoupper($name)) {
            $name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        }
        $city = $this->title($city);
        $state = $this->title($state);
        $contact = trim($contact) === '' ? '' : (trim($contact) === mb_strtoupper(trim($contact)) ? $this->title($contact) : trim($contact));
        $board = trim($board);
        $website = trim($website);

        return [
            'name' => mb_substr($name, 0, 191),
            'account_key' => mb_substr(StructuredAi::normalise($name) . '|' . StructuredAi::normalise($city), 0, 191),
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'board' => $board !== '' && (! $boardMustBeKnown || in_array($board, self::BOARDS, true)) ? mb_substr($board, 0, 40) : null,
            'level' => trim($level) !== '' ? mb_substr(trim($level), 0, 60) : null,
            'website' => $website !== '' ? mb_substr(preg_match('#^https?://#i', $website) ? $website : 'https://' . $website, 0, 500) : null,
            'crm_ref' => trim($crmRef) !== '' ? mb_substr(trim($crmRef), 0, 50) : null,
            'crm_owner' => trim($owner) !== '' ? mb_substr(trim($owner), 0, 100) : null,
            'contact_name' => $contact !== '' ? mb_substr($contact, 0, 191) : null,
            'attributes' => $attributes,
        ];
    }

    private function title(string $v): string
    {
        $v = trim(preg_replace('/\s+/', ' ', $v) ?? $v);

        return $v === '' ? '' : mb_convert_case(mb_strtolower($v), MB_CASE_TITLE);
    }
}
