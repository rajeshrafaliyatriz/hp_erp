<?php

namespace App\Domain\Gtm;

use Illuminate\Support\Facades\DB;

/**
 * CSV -> contacts, with a dry run that is the SAME code path as the commit.
 *
 * Nothing is trusted from the preview: a commit re-parses and re-validates, then inserts only the
 * rows that pass. A row is rejected, not repaired, when its name or email is unusable. Duplicates
 * are detected by email (case-insensitively) against this organisation's existing contacts AND
 * earlier rows of the same file; with no email, by account + name. An existing contact is never
 * overwritten. Accounts are matched by exact name or domain; unmatched ones are created only when
 * the caller asks.
 */
final class ContactImporter
{
    private const ROLES = ['champion', 'economic_buyer', 'decision_maker', 'influencer', 'user', 'blocker'];

    public const FIELDS = ['full_name', 'email', 'title', 'phone', 'linkedin_url', 'role_in_deal', 'company', 'domain', 'notes'];

    private const SYNONYMS = [
        'full_name' => ['name', 'full name', 'fullname', 'contact', 'contact name', 'person'],
        'email' => ['email', 'e-mail', 'email address', 'mail'],
        'title' => ['title', 'job title', 'designation', 'position', 'role title'],
        'phone' => ['phone', 'mobile', 'telephone', 'phone number', 'tel'],
        'linkedin_url' => ['linkedin', 'linkedin url', 'linkedin profile'],
        'role_in_deal' => ['role', 'role in deal', 'buying role'],
        'company' => ['company', 'company name', 'organisation', 'organization', 'account', 'account name', 'employer'],
        'domain' => ['domain', 'website', 'company website', 'company domain'],
        'notes' => ['notes', 'note', 'comments', 'comment'],
    ];

    /** @return array{headers: list<string>, rows: list<array<int, string>>, delimiter: string} */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (strlen($csv) > (int) config('gtm.import.max_bytes')) {
            throw new \DomainException('The file is too large to import in one go.');
        }
        $first = strtok($csv, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $headers = null;
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if ($r === [null] || count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $r = array_map(fn ($v) => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v) ?? ''), $r);
            if ($headers === null) {
                $headers = $r;

                continue;
            }
            $rows[] = $r;
            if (count($rows) > (int) config('gtm.import.max_rows')) {
                throw new \DomainException('The file has more than '.config('gtm.import.max_rows').' rows. Split it and import in parts.');
            }
        }
        fclose($fh);
        if ($headers === null || $rows === []) {
            throw new \DomainException('The file has no data rows.');
        }

        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /** Best guess of which header feeds which field. @param list<string> $headers @return array<string, ?int> */
    public function suggestMapping(array $headers): array
    {
        $map = array_fill_keys(self::FIELDS, null);
        foreach ($headers as $i => $h) {
            $n = mb_strtolower(trim($h));
            foreach (self::SYNONYMS as $field => $names) {
                if ($map[$field] === null && in_array($n, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, ?int>  $mapping  field => column index
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, accounts_to_create: list<string>}
     */
    public function run(int $tenant, ?int $userId, string $csv, array $mapping, ?int $defaultAccountId, bool $createAccounts, bool $commit): array
    {
        $parsed = $this->parse($csv);
        if (! isset($mapping['full_name']) || $mapping['full_name'] === null) {
            throw new \DomainException('Map a column to the contact name.');
        }
        if ($defaultAccountId !== null && ! GtmAccount::where('sub_institute_id', $tenant)->whereKey($defaultAccountId)->exists()) {
            throw new \DomainException('The default account is not one of your accounts.');
        }
        if ($defaultAccountId === null && ($mapping['company'] ?? null) === null && ($mapping['domain'] ?? null) === null) {
            throw new \DomainException('Choose a default account, or map the company or domain column so each contact can be placed.');
        }

        $accountsByName = GtmAccount::where('sub_institute_id', $tenant)->get(['id', 'name', 'domain'])->groupBy(fn ($a) => mb_strtolower(trim($a->name)));
        $accountNames = GtmAccount::where('sub_institute_id', $tenant)->pluck('name', 'id');
        $accountsByDomain = GtmAccount::where('sub_institute_id', $tenant)->whereNotNull('domain')->pluck('id', 'domain');
        $existingEmails = GtmContact::where('sub_institute_id', $tenant)->whereNotNull('email')->pluck('id', DB::raw('LOWER(email)'))->all();
        $existingNameKeys = GtmContact::where('sub_institute_id', $tenant)->whereNull('email')->get(['account_id', 'full_name'])->mapWithKeys(fn ($c) => [$c->account_id.'|'.mb_strtolower($c->full_name) => true])->all();

        $seenEmails = [];
        $seenNames = [];
        $newAccounts = [];
        $out = [];
        $summary = ['rows' => count($parsed['rows']), 'valid' => 0, 'duplicate' => 0, 'invalid' => 0, 'imported' => 0, 'accounts_created' => 0];
        $get = fn (array $r, string $f) => ($mapping[$f] ?? null) !== null ? trim((string) ($r[$mapping[$f]] ?? '')) : '';

        $work = [];
        foreach ($parsed['rows'] as $i => $r) {
            $line = $i + 2; // header is line 1
            $errors = [];
            $warnings = [];
            $name = mb_substr($get($r, 'full_name'), 0, 191);
            $email = mb_strtolower($get($r, 'email'));
            if ($name === '') {
                $errors[] = 'Name is empty.';
            }
            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191)) {
                $errors[] = "'{$email}' is not a valid email address.";
            }

            // account placement
            $accountId = $defaultAccountId;
            $newAccountName = null;
            if ($accountId === null) {
                $company = $get($r, 'company');
                $domain = $this->domain($get($r, 'domain'));
                if ($company !== '' && isset($accountsByName[mb_strtolower($company)])) {
                    $accountId = (int) $accountsByName[mb_strtolower($company)][0]->id;
                } elseif ($domain !== null && isset($accountsByDomain[$domain])) {
                    $accountId = (int) $accountsByDomain[$domain];
                } elseif ($company !== '' && $createAccounts) {
                    $newAccountName = mb_substr($company, 0, 191);
                } else {
                    $errors[] = $company !== '' ? "No account named '{$company}'. Add it first, or tick 'create missing accounts'." : 'No company or domain, and no default account chosen.';
                }
            }

            $role = $get($r, 'role_in_deal');
            if ($role !== '') {
                $role = str_replace([' ', '-'], '_', mb_strtolower($role));
                if (! in_array($role, self::ROLES, true)) {
                    $warnings[] = "Role '{$get($r, 'role_in_deal')}' is not recognised and was left blank.";
                    $role = '';
                }
            }
            $linkedin = $get($r, 'linkedin_url');
            if ($linkedin !== '' && ! preg_match('#^https?://#i', $linkedin)) {
                $warnings[] = 'LinkedIn value is not a URL and was left blank.';
                $linkedin = '';
            }

            $status = 'ok';
            if ($errors === []) {
                $nameKey = ($accountId ?? 'new:'.mb_strtolower((string) $newAccountName)).'|'.mb_strtolower($name);
                if ($email !== '') {
                    if (isset($existingEmails[$email])) {
                        $status = 'duplicate';
                        $errors[] = 'A contact with this email already exists.';
                    } elseif (isset($seenEmails[$email])) {
                        $status = 'duplicate';
                        $errors[] = "Same email as row {$seenEmails[$email]} of this file.";
                    }
                } elseif (isset($existingNameKeys[$nameKey]) || isset($seenNames[$nameKey])) {
                    $status = 'duplicate';
                    $errors[] = 'A contact with this name and no email already exists at this account.';
                }
                if ($status === 'ok') {
                    if ($email !== '') {
                        $seenEmails[$email] = $line;
                    } else {
                        $seenNames[$nameKey] = $line;
                    }
                }
            } else {
                $status = 'invalid';
            }
            $summary[$status === 'ok' ? 'valid' : $status]++;
            if ($newAccountName && $status === 'ok') {
                $newAccounts[mb_strtolower($newAccountName)] = $newAccountName;
            }

            $out[] = ['line' => $line, 'status' => $status, 'errors' => $errors, 'warnings' => $warnings, 'name' => $name, 'email' => $email ?: null, 'account' => $newAccountName ?? ($accountId ? ($accountNames[$accountId] ?? null) : null), 'creates_account' => $newAccountName !== null];
            if ($status === 'ok') {
                $work[] = ['name' => $name, 'email' => $email ?: null, 'title' => mb_substr($get($r, 'title'), 0, 191) ?: null, 'phone' => mb_substr($get($r, 'phone'), 0, 50) ?: null,
                    'linkedin' => $linkedin ?: null, 'role' => $role ?: null, 'notes' => mb_substr($get($r, 'notes'), 0, 4000) ?: null, 'account_id' => $accountId, 'new_account' => $newAccountName];
            }
        }

        if ($commit && $work !== []) {
            DB::transaction(function () use ($tenant, $userId, $work, &$summary) {
                $created = [];
                foreach ($work as $w) {
                    $accountId = $w['account_id'];
                    if ($accountId === null) {
                        $key = mb_strtolower($w['new_account']);
                        if (! isset($created[$key])) {
                            $created[$key] = GtmAccount::create(['sub_institute_id' => $tenant, 'name' => $w['new_account'], 'stage' => 'target', 'source' => 'import', 'created_by' => $userId])->id;
                            $summary['accounts_created']++;
                        }
                        $accountId = $created[$key];
                    }
                    GtmContact::create([
                        'sub_institute_id' => $tenant, 'account_id' => $accountId, 'full_name' => $w['name'], 'title' => $w['title'], 'email' => $w['email'],
                        'phone' => $w['phone'], 'linkedin_url' => $w['linkedin'], 'role_in_deal' => $w['role'], 'status' => 'active', 'source' => 'import',
                        'notes' => $w['notes'], 'created_by' => $userId,
                    ]);
                    $summary['imported']++;
                }
            });
            GtmAudit::record('gtm.contacts.imported', $tenant, 'gtm_contacts', null, $userId, ['summary' => $summary]);
        }

        return ['summary' => $summary, 'rows' => array_slice($out, 0, 300), 'accounts_to_create' => array_values($newAccounts)];
    }

    private function domain(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST) ?: $value;

        return preg_replace('/^www\./', '', $host) ?: null;
    }
}
