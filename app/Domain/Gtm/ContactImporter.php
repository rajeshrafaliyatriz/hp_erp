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
        'domain' => ['domain', 'website', 'company website', 'company domain', 'company website domain', 'website domain', 'web site', 'company site', 'site', 'url', 'website url', 'company url'],
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
            // "Company website / domain", "Company  Website" and "company_website" are the same header.
            $n = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($h)) ?? '');
            foreach (self::SYNONYMS as $field => $names) {
                if ($map[$field] === null && in_array($n, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }

        return $map;
    }

    /**
     * Each row of the file is a CONTACT at a COMPANY, and the two are saved separately:
     *   contact  Name, Email, Job title, Phone, LinkedIn URL, Buying role, Notes   -> gtm_contacts
     *   company  Company (name), Company website / domain, Notes                   -> gtm_accounts (name, domain, website, notes, location)
     * A row whose name says "(general contact)" is the company's own address book entry, so its email and phone are also kept on the company.
     * Every company in the file is created, or, when it already exists on the chosen list, its EMPTY fields are filled (nothing already there is
     * overwritten and no second account is made). That does not depend on the contact: a contact that is a duplicate or unusable never stops its
     * company from being saved.
     *
     * @param  array<string, ?int>  $mapping  field => column index
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, accounts_to_create: list<string>, accounts_to_update: list<string>}
     */
    public function run(int $tenant, ?int $userId, string $csv, array $mapping, ?int $defaultAccountId, bool $createAccounts, bool $commit, array $scope = ['product' => null, 'model' => null]): array
    {
        $parsed = $this->parse($csv);
        if (! isset($mapping['full_name']) || $mapping['full_name'] === null) {
            throw new \DomainException('Map a column to the contact name.');
        }
        if ($defaultAccountId !== null && ! GtmAccount::where('sub_institute_id', $tenant)->whereKey($defaultAccountId)->exists()) {
            throw new \DomainException('The default account is not one of your accounts.');
        }
        // With a product, customer type or region chosen, contacts go only to accounts on that list: lists are never mixed, not even by a chosen account.
        if ($defaultAccountId !== null && ProspectScope::isFiltered($scope)
            && ! ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant)->whereKey($defaultAccountId), $scope)->exists()) {
            throw new \DomainException('The chosen account is not on the selected product, customer type and region list.');
        }
        if ($defaultAccountId === null && ($mapping['company'] ?? null) === null && ($mapping['domain'] ?? null) === null) {
            throw new \DomainException('Choose a default account, or map the company or domain column so each contact can be placed.');
        }

        // Accounts are matched inside the chosen product, customer type and region only, so a firm already on another list is added again here, not reused.
        $inScope = fn () => ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant), $scope);
        $columns = ['id', 'name', 'domain', 'website', 'location', 'notes', 'email', 'phone', 'region_country'];
        $existing = $inScope()->get($columns)->keyBy('id');
        // Accounts of this product and customer type that were saved before regions existed have no region yet. When the import is for a region they
        // are the same companies, so they are reused (and moved into the region), not added a second time. Accounts that already belong to ANOTHER
        // region are never taken.
        if (($scope['region'] ?? null) !== null) {
            $existing = $existing->union(ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant), ['region' => null] + $scope)
                ->whereNull('region_country')->get($columns)->keyBy('id'));
        }
        $accountsByName = $existing->groupBy(fn ($a) => mb_strtolower(trim($a->name)));
        $accountNames = $existing->pluck('name', 'id');
        $accountsByDomain = $existing->filter(fn ($a) => $a->domain !== null && $a->domain !== '')->pluck('id', 'domain');
        // An account that was removed still holds its website in the one-account-per-website rule, so it is brought back rather than duplicated.
        $removedByDomain = $defaultAccountId === null
            ? ProspectScope::apply(GtmAccount::onlyTrashed()->where('sub_institute_id', $tenant)->whereNotNull('domain'), $scope)->pluck('id', 'domain')->all()
            : [];
        $existingEmails = GtmContact::where('sub_institute_id', $tenant)->whereNotNull('email')->pluck('id', DB::raw('LOWER(email)'))->all();
        $existingNameKeys = GtmContact::where('sub_institute_id', $tenant)->whereNull('email')->get(['account_id', 'full_name'])->mapWithKeys(fn ($c) => [$c->account_id.'|'.mb_strtolower($c->full_name) => true])->all();

        $suppressed = array_flip(app(\App\Domain\Gtm\Outreach\Suppressions::class)->among($tenant, array_filter(array_map(fn ($r) => mb_strtolower(trim((string) ($r[$mapping['email'] ?? -1] ?? ''))), $parsed['rows']))));
        $seenEmails = [];
        $seenNames = [];
        $companies = [];      // company key => what the file says about it
        $domainKeys = [];     // website => company key, so two spellings of one company in the file are one account
        $out = [];
        $summary = ['rows' => count($parsed['rows']), 'valid' => 0, 'duplicate' => 0, 'invalid' => 0, 'imported' => 0,
            'companies' => 0, 'accounts_created' => 0, 'accounts_updated' => 0];
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

            // company placement: found on this list, restored, created, or not possible
            $accountId = $defaultAccountId;
            $companyKey = null;
            $newAccountName = null;
            $company = $get($r, 'company');
            $rawSite = $get($r, 'domain');
            $domain = $this->domain($rawSite);
            if ($accountId === null) {
                if ($company !== '' && isset($accountsByName[mb_strtolower($company)])) {
                    $accountId = (int) $accountsByName[mb_strtolower($company)][0]->id;
                } elseif ($domain !== null && isset($accountsByDomain[$domain])) {
                    $accountId = (int) $accountsByDomain[$domain];
                } elseif ($domain !== null && isset($removedByDomain[$domain]) && ($createAccounts || $company === '')) {
                    $accountId = (int) $removedByDomain[$domain];
                    $companyKey = 'restore:'.$accountId;
                } elseif ($company !== '' && $createAccounts) {
                    $newAccountName = mb_substr($company, 0, 191);
                    $companyKey = $domain !== null && isset($domainKeys[$domain]) ? $domainKeys[$domain] : 'new:'.mb_strtolower($newAccountName);
                } else {
                    $errors[] = $company !== '' ? "No account named '{$company}'. Add it first, or tick 'create missing accounts'." : 'No company or domain, and no default account chosen.';
                }
            }
            if ($companyKey === null && $accountId !== null && $defaultAccountId === null) {
                $companyKey = 'id:'.$accountId;
            }

            // what the file says about the company, collected from every row that names it
            if ($companyKey !== null) {
                $c = &$companies[$companyKey];
                $c ??= ['id' => $accountId, 'name' => $newAccountName ?? (str_starts_with($companyKey, 'restore:') && $company !== '' ? mb_substr($company, 0, 191) : null), 'domain' => null, 'website' => null, 'notes' => [], 'location' => null, 'email' => null, 'phone' => null, 'rows' => 0];
                $c['rows']++;
                if ($domain !== null) {
                    $c['domain'] ??= $domain;
                    $c['website'] ??= $this->website($rawSite);
                    $domainKeys[$domain] ??= $companyKey;
                }
                $note = mb_substr($get($r, 'notes'), 0, 4000);
                if ($note !== '' && ! in_array($note, $c['notes'], true)) {
                    $c['notes'][] = $note;
                    $c['location'] ??= $this->cityFromNote($note, $scope);
                }
                if (preg_match('/general\s+contact/i', $name) === 1) {
                    $c['email'] ??= ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null);
                    $c['phone'] ??= (($p = mb_substr($get($r, 'phone'), 0, 50)) !== '' ? $p : null);
                }
                unset($c);
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

            if ($email !== '' && $errors === [] && isset($suppressed[$email])) {
                $warnings[] = 'This address is on the suppression list: the contact is saved but will never be emailed.';
            }
            $status = 'ok';
            if ($errors === []) {
                $nameKey = ($accountId ?? $companyKey ?? 'new:'.mb_strtolower((string) $newAccountName)).'|'.mb_strtolower($name);
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

            $out[] = ['line' => $line, 'status' => $status, 'errors' => $errors, 'warnings' => $warnings, 'name' => $name, 'email' => $email ?: null,
                'account' => $newAccountName ?? ($accountId ? ($accountNames[$accountId] ?? null) : null), 'creates_account' => $newAccountName !== null];
            if ($status === 'ok') {
                $work[] = ['name' => $name, 'email' => $email ?: null, 'title' => mb_substr($get($r, 'title'), 0, 191) ?: null, 'phone' => mb_substr($get($r, 'phone'), 0, 50) ?: null,
                    'linkedin' => $linkedin ?: null, 'role' => $role ?: null, 'notes' => mb_substr($get($r, 'notes'), 0, 4000) ?: null, 'account_id' => $defaultAccountId ?? $accountId,
                    'company_key' => $defaultAccountId === null ? $companyKey : null];
            }
        }

        // What will happen to each company: created, or its empty fields filled. Worked out the same way for the check and for the import.
        $plan = [];
        foreach ($companies as $key => $c) {
            $current = $c['id'] !== null ? $existing->get($c['id']) : null;
            $fills = $current === null ? array_filter(['domain' => $c['domain'], 'website' => $c['website'], 'location' => $c['location'], 'email' => $c['email'], 'phone' => $c['phone']], fn ($v) => $v !== null)
                : $this->fillsFor($current, $c, $accountsByDomain, $scope['region'] ?? null);
            $notes = $this->mergedNotes($current?->notes, $c['notes']);
            if ($notes !== null && ($current === null || $notes !== (string) $current->notes)) {
                $fills['notes'] = $notes;
            }
            $plan[$key] = ['id' => $c['id'], 'name' => $c['name'] ?? ($current?->name), 'fills' => $fills, 'restore' => str_starts_with($key, 'restore:')];
            $summary['companies']++;
            if ($c['id'] === null) {
                $summary['accounts_created']++;
            } elseif ($fills !== [] || $plan[$key]['restore']) {
                $summary['accounts_updated']++;
            }
        }
        $toCreate = array_values(array_filter(array_map(fn ($p) => $p['id'] === null ? $p['name'] : null, $plan)));
        $toUpdate = array_values(array_filter(array_map(fn ($p) => ($p['id'] !== null && ($p['fills'] !== [] || $p['restore'])) ? ($p['name'] ?? ($accountNames[$p['id']] ?? null)) : null, $plan)));

        if ($commit && ($work !== [] || $plan !== [])) {
            // Counts of what really happened replace the plan's counts.
            $summary['accounts_created'] = 0;
            $summary['accounts_updated'] = 0;
            DB::transaction(function () use ($tenant, $userId, $work, $plan, $scope, &$summary) {
                $ids = [];
                foreach ($plan as $key => $p) {
                    if ($p['id'] === null) {
                        $account = GtmAccount::create(['sub_institute_id' => $tenant, 'name' => $p['name'], 'stage' => 'target', 'source' => 'import', 'created_by' => $userId,
                            'product_key' => $scope['product'], 'customer_model' => $scope['model']] + ProspectScope::regionColumns($scope) + $p['fills']);
                        $ids[$key] = $account->id;
                        $summary['accounts_created']++;

                        continue;
                    }
                    $ids[$key] = $p['id'];
                    if ($p['fills'] !== [] || $p['restore']) {
                        $account = GtmAccount::withTrashed()->where('sub_institute_id', $tenant)->find($p['id']);
                        if ($account !== null) {
                            if ($p['restore']) {
                                $account->restore();
                            }
                            $account->fill($p['fills'])->save();
                            $summary['accounts_updated']++;
                        }
                    }
                }
                foreach ($work as $w) {
                    $accountId = $w['account_id'] ?? ($w['company_key'] !== null ? ($ids[$w['company_key']] ?? null) : null);
                    if ($accountId === null) {
                        continue;
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

        return ['summary' => $summary, 'rows' => array_slice($out, 0, 300), 'accounts_to_create' => $toCreate, 'accounts_to_update' => $toUpdate];
    }

    /** The fields of an existing account that the file can fill: only the empty ones, never an overwrite. @return array<string, string> */
    private function fillsFor(object $account, array $c, $accountsByDomain, ?array $region = null): array
    {
        // A website another account on this list already has is never given to a second one (one account per website).
        $siteTaken = $c['domain'] !== null && isset($accountsByDomain[$c['domain']]) && (int) $accountsByDomain[$c['domain']] !== (int) $account->id;
        $fills = [];
        if ($region !== null && trim((string) ($account->region_country ?? '')) === '') {
            $fills += ProspectScope::regionColumns(['region' => $region]);   // a company saved before regions existed joins the chosen region
        }
        foreach (['domain', 'website', 'location', 'email', 'phone'] as $field) {
            if ($siteTaken && in_array($field, ['domain', 'website'], true)) {
                continue;
            }
            if ($c[$field] !== null && trim((string) ($account->{$field} ?? '')) === '') {
                $fills[$field] = $c[$field];
            }
        }

        return $fills;
    }

    /** The notes an account will have: what it has, plus each new line from the file that it does not already say. Null when there is nothing. */
    private function mergedNotes(?string $current, array $fromFile): ?string
    {
        $text = trim((string) $current);
        foreach ($fromFile as $line) {
            if ($line !== '' && ! str_contains($text, $line)) {
                $text = $text === '' ? $line : $text."\n".$line;
            }
        }

        return $text === '' ? null : mb_substr($text, 0, 4000);
    }

    /**
     * The city a note opens with ("Ahmedabad. HR strategy ..." / "Pune | Sections A ..."), but ONLY when the location data knows it as a city of
     * the chosen country (and state). A word that merely starts a note ("Website only.") is never taken for a place.
     */
    private function cityFromNote(string $note, array $scope): ?string
    {
        $region = $scope['region'] ?? null;
        if ($region === null || ! preg_match('/^\s*([\p{L}][\p{L} .\'-]{1,40}?)\s*(?:[.|,;:\n]|$)/u', $note, $m)) {
            return null;
        }
        $candidate = trim($m[1]);
        $catalog = \App\Domain\Signals\Locations\LocationCatalog::shared();
        $country = $catalog->country($region['country']);
        if ($country === null) {
            return null;
        }
        $states = $region['state'] !== null ? array_filter([$catalog->state($country['code'], $region['state'])]) : $catalog->states($country['code']);
        foreach ($states as $state) {
            $city = $catalog->city($country['code'], $state['code'], $candidate);
            if ($city !== null) {
                return $city['name'];
            }
        }

        return null;
    }

    /** The address as the person gave it, with a scheme when it has none (a bare domain is shown as a link). */
    private function website(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr(preg_match('#^https?://#i', $value) === 1 ? $value : 'https://'.$value, 0, 255);
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
