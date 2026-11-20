<?php

namespace App\Domain\Gtm;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;

/**
 * CSV -> companies (accounts) and their contacts, with a dry run that is the SAME code path as the commit.
 *
 * Each row is a CONTACT at a COMPANY and the two are saved separately:
 *   contact  Name, Email, Job title, Phone, LinkedIn URL, Buying role, Notes   -> gtm_contacts
 *   company  Company (name), Company website / domain, Notes                   -> gtm_accounts (name, domain, website, notes, location)
 * Any OTHER column in the file (research source, status, confidence ...) is kept on the company under its own heading, so nothing in a file is lost.
 * A row that is the company's own address book entry ("(general contact)") also gives the company its email and phone.
 *
 * Companies
 *   - Matched inside the chosen product, customer type and region by website first, then by exact name; unmatched ones are created.
 *   - Rows that share a website are ONE company, even if the names differ ("BRIOS Consulting" / "BRIOS Consulting LLP").
 *   - An existing company has its EMPTY fields filled; nothing already there is overwritten. A company saved before regions existed joins the region.
 *   - An existing duplicate (same website, an import-made account that nothing else points to) is merged into the company: its contacts move across and
 *     it is removed. One that is linked to deals, activities, the CRM and so on is never touched.
 * Contacts
 *   - Cells with several emails or phones keep the first in the field and the rest in the contact's notes.
 *   - A contact that already exists (same email, or same name at the same company) has its empty fields filled in; it is not added twice.
 *   - A contact that cannot be saved never stops its company from being saved.
 */
final class ContactImporter
{
    /** @var list<string>|null the tables that point at accounts, found once per import */
    private ?array $pointers = null;

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
     * @param  array<string, ?int>  $mapping  field => column index
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>, accounts_to_create: list<string>, accounts_to_update: list<string>, accounts_to_merge: list<string>}
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

        $headers = $parsed['headers'];
        $rows = $parsed['rows'];
        $get = fn (array $r, string $f) => ($mapping[$f] ?? null) !== null ? trim((string) ($r[$mapping[$f]] ?? '')) : '';
        // Columns that are not one of the nine fields: kept on the company under their own heading.
        $mapped = array_filter(array_values($mapping), fn ($v) => $v !== null);
        $extraColumns = [];
        foreach ($headers as $idx => $h) {
            if (! in_array($idx, $mapped, true) && trim($h) !== '') {
                $extraColumns[$idx] = trim($h);
            }
        }
        $keepsDetails = $extraColumns !== [] && ColumnExists::has('gtm_accounts', 'import_details');

        // ── what is already on this list ────────────────────────────────────────────────────────────────────────────────────────────────────
        $columns = ['id', 'name', 'domain', 'website', 'location', 'notes', 'email', 'phone', 'region_country', 'source'];
        foreach (['import_details', 'crm_organization_id', 'company_id'] as $optional) {
            if (ColumnExists::has('gtm_accounts', $optional)) {
                $columns[] = $optional;
            }
        }
        $inScope = fn () => ProspectScope::apply(GtmAccount::where('sub_institute_id', $tenant), $scope);
        $existing = $inScope()->get($columns)->keyBy('id');
        // Accounts of this product and customer type saved before regions existed have no region yet. When the import is for a region they are the
        // same companies, so they are reused (and moved into the region), not added a second time. Accounts of ANOTHER region are never taken.
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

        // ── pass 1: which company is each row about? Rows that share a website are one company (a union-find over account / website / name) ──────
        $uf = new class {
            /** @var array<string, string> */
            public array $p = [];

            public function find(string $x): string
            {
                $this->p[$x] ??= $x;
                while ($this->p[$x] !== $x) {
                    $this->p[$x] = $this->p[$this->p[$x]];
                    $x = $this->p[$x];
                }

                return $x;
            }

            public function union(string $a, string $b): void
            {
                $ra = $this->find($a);
                $rb = $this->find($b);
                if ($ra !== $rb) {
                    $this->p[$rb] = $ra;
                }
            }
        };
        $info = [];
        foreach ($rows as $i => $r) {
            $company = $get($r, 'company');
            $rawSite = $get($r, 'domain');
            $domain = $this->domain($rawSite);
            $node = null;
            $problem = null;
            $also = [];
            if ($defaultAccountId === null) {
                $n = $company !== '' && isset($accountsByName[mb_strtolower($company)]) ? (int) $accountsByName[mb_strtolower($company)][0]->id : null;
                $d = $domain !== null && isset($accountsByDomain[$domain]) ? (int) $accountsByDomain[$domain] : null;
                $rm = $domain !== null && isset($removedByDomain[$domain]) ? (int) $removedByDomain[$domain] : null;
                if ($d !== null) {
                    $node = 'id:'.$d;
                    if ($n !== null && $n !== $d && trim((string) $existing[$n]->domain) === '') {
                        $uf->union('id:'.$n, $node);          // the same company saved twice: one with the website, one without
                        $also[] = 'id:'.$n;
                    }
                } elseif ($n !== null) {
                    $node = 'id:'.$n;
                } elseif ($rm !== null && ($createAccounts || $company === '')) {
                    $node = 'restore:'.$rm;
                } elseif ($company !== '' && $createAccounts) {
                    $node = 'new:'.mb_strtolower(mb_substr($company, 0, 191));
                } else {
                    $problem = $company !== '' ? "No account named '{$company}'. Add it first, or tick 'create missing accounts'." : 'No company or domain, and no default account chosen.';
                }
                if ($node !== null && $domain !== null) {
                    $uf->union($node, 'dom:'.$domain);
                }
            }
            $info[$i] = ['company' => $company, 'raw' => $rawSite, 'domain' => $domain, 'node' => $node, 'problem' => $problem, 'also' => $also];
        }

        // One company per group. Its survivor is the account that owns the website, else the best existing account, else a new one.
        $groups = [];
        foreach ($info as $i => $x) {
            if ($x['node'] !== null) {
                $root = $uf->find($x['node']);
                $groups[$root]['nodes'][$x['node']] = true;
                foreach ($x['also'] as $other) {
                    $groups[$root]['nodes'][$other] = true;
                }
                $groups[$root]['first'] ??= $i;
                if ($x['domain'] !== null) {
                    $groups[$root]['domains'][$x['domain']] = true;
                }
            }
        }
        $keyOfRoot = [];
        $keyOfSurvivor = [];
        $merge = [];        // duplicate account id => survivor account id
        foreach ($groups as $root => $g) {
            $ids = [];
            foreach (array_keys($g['nodes']) as $node) {
                if (str_starts_with($node, 'id:')) {
                    $ids[] = (int) substr($node, 3);
                }
            }
            $survivor = null;
            $kind = 'id';
            foreach (array_keys($g['domains'] ?? []) as $dom) {
                if (isset($accountsByDomain[$dom])) {
                    $survivor = (int) $accountsByDomain[$dom];
                    break;
                }
                if (isset($removedByDomain[$dom])) {
                    $survivor = (int) $removedByDomain[$dom];
                    $kind = 'restore';
                    break;
                }
            }
            if ($survivor === null && $ids !== []) {
                usort($ids, fn ($a, $b) => [trim((string) $existing[$a]->region_country) === '' ? 1 : 0, $a] <=> [trim((string) $existing[$b]->region_country) === '' ? 1 : 0, $b]);
                $survivor = $ids[0];
            }
            if ($survivor === null) {
                $firstRow = $rows[$g['first']];
                $groups[$root]['name'] = mb_substr($get($firstRow, 'company'), 0, 191);
                $keyOfRoot[$root] = 'new:'.mb_strtolower($groups[$root]['name']);

                continue;
            }
            $keyOfRoot[$root] = ($kind === 'restore' ? 'restore:' : 'id:').$survivor;
            $keyOfSurvivor[$survivor] = $keyOfRoot[$root];
            foreach ($ids as $dup) {
                if ($dup !== $survivor && $this->mergeable($tenant, $existing[$dup])) {
                    $merge[$dup] = $survivor;
                }
            }
        }

        // ── pass 2: the companies' details and the contacts ─────────────────────────────────────────────────────────────────────────────────
        $companies = [];
        $ensure = function (string $key, ?int $id, ?string $name) use (&$companies): void {
            $companies[$key] ??= ['id' => $id, 'name' => $name, 'domain' => null, 'website' => null, 'notes' => [], 'location' => null, 'email' => null,
                'phone' => null, 'extras' => [], 'merged' => []];
        };

        $fileEmails = [];
        foreach ($rows as $r) {
            foreach ($this->emails($get($r, 'email'))['all'] as $e) {
                $fileEmails[$e] = true;
            }
        }
        $existingByEmail = [];
        foreach (array_chunk(array_keys($fileEmails), 400) as $chunk) {
            foreach (GtmContact::where('sub_institute_id', $tenant)->whereIn(DB::raw('LOWER(email)'), $chunk)
                ->get(['id', 'account_id', 'full_name', 'email', 'title', 'phone', 'linkedin_url', 'role_in_deal', 'notes']) as $c) {
                $existingByEmail[mb_strtolower((string) $c->email)] = $c;
            }
        }
        $existingNoEmail = [];
        if ($existing->isNotEmpty()) {
            foreach (array_chunk($existing->keys()->all(), 500) as $chunk) {
                foreach (GtmContact::where('sub_institute_id', $tenant)->whereNull('email')->whereIn('account_id', $chunk)
                    ->get(['id', 'account_id', 'full_name', 'title', 'phone', 'linkedin_url', 'role_in_deal', 'notes']) as $c) {
                    $existingNoEmail[$c->account_id.'|'.mb_strtolower($c->full_name)] = $c;
                }
            }
        }
        $suppressed = array_flip(app(\App\Domain\Gtm\Outreach\Suppressions::class)->among($tenant, array_keys($fileEmails)));

        $seenEmails = [];
        $seenNames = [];
        $workIdx = [];
        $out = [];
        $summary = ['rows' => count($rows), 'valid' => 0, 'duplicate' => 0, 'invalid' => 0, 'imported' => 0, 'contacts_updated' => 0,
            'companies' => 0, 'accounts_created' => 0, 'accounts_updated' => 0, 'accounts_merged' => 0];
        $work = [];

        foreach ($rows as $i => $r) {
            $line = $i + 2; // header is line 1
            $errors = [];
            $warnings = [];
            $x = $info[$i];
            $name = mb_substr($get($r, 'full_name'), 0, 191);
            $emailCell = $get($r, 'email');
            $em = $this->emails($emailCell);
            $email = $em['primary'] ?? '';
            if ($name === '') {
                $errors[] = 'Name is empty.';
            }
            if ($emailCell !== '' && $email === '') {
                $errors[] = "'{$emailCell}' is not a valid email address.";
            } elseif ($email !== '' && mb_strlen($email) > 191) {
                $errors[] = 'The email address is too long.';
            }

            // the company this row is about
            $accountId = $defaultAccountId;
            $companyKey = null;
            $newAccountName = null;
            if ($defaultAccountId === null) {
                if ($x['problem'] !== null) {
                    $errors[] = $x['problem'];
                } else {
                    $root = $uf->find($x['node']);
                    $companyKey = $keyOfRoot[$root];
                    if (str_starts_with($companyKey, 'id:') || str_starts_with($companyKey, 'restore:')) {
                        $accountId = (int) substr($companyKey, (int) strpos($companyKey, ':') + 1);
                    } else {
                        $newAccountName = $groups[$root]['name'] ?? $x['company'];
                    }
                }
            }

            if ($companyKey !== null) {
                $ensure($companyKey, $accountId, $newAccountName);
                $c = &$companies[$companyKey];
                if ($x['domain'] !== null) {
                    $c['domain'] ??= $x['domain'];
                    $c['website'] ??= $this->website($x['raw']);
                }
                $note = mb_substr($get($r, 'notes'), 0, 4000);
                if ($note !== '' && ! in_array($note, $c['notes'], true)) {
                    $c['notes'][] = $note;
                    $c['location'] ??= $this->cityFromNote($note, $scope);
                }
                if (preg_match('/general\s+(company\s+)?contact/i', $name.' '.$get($r, 'role_in_deal')) === 1) {
                    $c['email'] ??= $email !== '' ? $email : null;
                    $c['phone'] ??= $this->phones($get($r, 'phone'))['primary'];
                }
                if ($keepsDetails) {
                    foreach ($extraColumns as $idx => $heading) {
                        $v = trim((string) ($r[$idx] ?? ''));
                        if ($v !== '' && ! in_array($v, $c['extras'][$heading] ?? [], true)) {
                            $c['extras'][$heading][] = mb_substr($v, 0, 1000);
                        }
                    }
                }
                unset($c);
            }

            // the contact
            $role = $this->role($get($r, 'role_in_deal'));
            $linkedin = $get($r, 'linkedin_url');
            if ($linkedin !== '' && ! preg_match('#^https?://#i', $linkedin)) {
                $warnings[] = 'LinkedIn value is not a URL and was left blank.';
                $linkedin = '';
            }
            $ph = $this->phones($get($r, 'phone'));
            $noteLines = array_values(array_filter([
                $get($r, 'notes'),
                $em['others'] !== [] ? 'Other email: '.implode(', ', $em['others']) : null,
                $ph['others'] !== [] ? 'Other phone: '.implode(', ', $ph['others']) : null,
                $get($r, 'role_in_deal') !== '' && $role['exact'] === false ? 'Buying role (as written): '.$get($r, 'role_in_deal') : null,
            ], fn ($v) => $v !== null && $v !== ''));
            $contact = ['name' => $name, 'email' => $email ?: null, 'title' => mb_substr($get($r, 'title'), 0, 191) ?: null,
                'phone' => $ph['primary'] !== null ? mb_substr($ph['primary'], 0, 50) : null,
                'linkedin' => $linkedin ?: null, 'role' => $role['value'] ?: null, 'lines' => $noteLines];

            if ($email !== '' && $errors === [] && isset($suppressed[$email])) {
                $warnings[] = 'This address is on the suppression list: the contact is saved but will never be emailed.';
            }

            // which accounts count as "this company" when looking for a contact that is already there: the survivor and what is merged into it
            $related = [];
            if ($accountId !== null) {
                $related = [$accountId];
                foreach ($merge as $dup => $survivor) {
                    if ($survivor === $accountId) {
                        $related[] = $dup;
                    }
                }
            }
            $status = 'ok';
            $update = null;
            if ($errors === []) {
                $nameKey = ($accountId ?? $companyKey ?? 'new:'.mb_strtolower((string) $newAccountName)).'|'.mb_strtolower($name);
                $found = null;
                if ($email !== '') {
                    $found = $existingByEmail[$email] ?? null;
                    if ($found !== null && ! in_array((int) $found->account_id, $related, true)) {
                        $status = 'duplicate';
                        $errors[] = 'A contact with this email already exists at another company.';
                        $found = null;
                    } elseif ($found === null && isset($seenEmails[$email])) {
                        $status = 'duplicate';
                        $errors[] = "Same email as row {$seenEmails[$email]} of this file: any extra details were added to that contact.";
                        $this->foldIntoPending($work, $workIdx['e:'.$email] ?? null, $contact);
                    }
                } else {
                    foreach ($related as $rid) {
                        $found ??= $existingNoEmail[$rid.'|'.mb_strtolower($name)] ?? null;
                    }
                    if ($found === null && isset($seenNames[$nameKey])) {
                        $status = 'duplicate';
                        $errors[] = 'A contact with this name and no email is already in this file for this company: any extra details were added to it.';
                        $this->foldIntoPending($work, $workIdx['n:'.$nameKey] ?? null, $contact);
                    }
                }
                if ($status === 'ok' && $found !== null) {
                    $fills = $this->contactFills($found, $contact);
                    if ($fills === []) {
                        $status = 'duplicate';
                        $errors[] = $email !== '' ? 'A contact with this email already exists (nothing new to add).' : 'A contact with this name already exists at this company (nothing new to add).';
                    } else {
                        $status = 'update';
                        $update = ['id' => $found->id, 'fills' => $fills, 'contact' => $contact, 'found' => $found];
                        $warnings[] = 'Already in your list: its missing details are filled in.';
                    }
                }
                if ($status === 'ok' || $status === 'update') {
                    if ($email !== '') {
                        $seenEmails[$email] = $line;
                    } else {
                        $seenNames[$nameKey] = $line;
                    }
                }
            } else {
                $status = 'invalid';
            }
            $summary[match ($status) {'ok' => 'valid', 'update' => 'contacts_updated', default => $status}]++;

            $out[] = ['line' => $line, 'status' => $status, 'errors' => $errors, 'warnings' => $warnings, 'name' => $name, 'email' => $email ?: null,
                'account' => $newAccountName ?? ($accountId ? ($accountNames[$accountId] ?? null) : null), 'creates_account' => $newAccountName !== null];
            if ($status === 'ok') {
                $work[] = ['kind' => 'create', 'contact' => $contact, 'account_id' => $defaultAccountId ?? $accountId, 'company_key' => $defaultAccountId === null ? $companyKey : null];
            } elseif ($status === 'update') {
                $work[] = ['kind' => 'update'] + $update;
            }
            if ($status === 'ok' || $status === 'update') {
                $workIdx[$email !== '' ? 'e:'.$email : 'n:'.$nameKey] = array_key_last($work);
            }
        }

        // a duplicate that is merged brings what it knows to the company it merges into
        foreach ($merge as $dup => $survivor) {
            $key = $keyOfSurvivor[$survivor] ?? 'id:'.$survivor;
            $d = $existing[$dup];
            $ensure($key, $survivor, null);
            $c = &$companies[$key];
            $c['merged'][$dup] = $d->name;
            foreach (['website', 'location', 'email', 'phone'] as $f) {
                $c[$f] ??= trim((string) $d->{$f}) !== '' ? $d->{$f} : null;
            }
            if (trim((string) $d->notes) !== '') {
                $c['notes'][] = trim((string) $d->notes);
            }
            unset($c);
        }

        // ── what will happen to each company: created, or its empty fields filled (worked out the same way for the check and the import) ───────
        $plan = [];
        foreach ($companies as $key => $c) {
            $current = $c['id'] !== null ? $existing->get($c['id']) : null;
            $fills = $current === null
                ? array_filter(['domain' => $c['domain'], 'website' => $c['website'], 'location' => $c['location'], 'email' => $c['email'], 'phone' => $c['phone']], fn ($v) => $v !== null)
                : $this->fillsFor($current, $c, $accountsByDomain, $scope['region'] ?? null);
            $notes = $this->mergedNotes($current?->notes, $c['notes']);
            if ($notes !== null && ($current === null || $notes !== (string) $current->notes)) {
                $fills['notes'] = $notes;
            }
            if ($keepsDetails && $c['extras'] !== []) {
                $details = $this->mergedDetails($current?->import_details ?? null, $c['extras']);
                if ($current === null || $details !== (array) ($current->import_details ?? [])) {
                    $fills['import_details'] = $details;
                }
            }
            $plan[$key] = ['id' => $c['id'], 'name' => $c['name'] ?? ($current?->name), 'fills' => $fills, 'restore' => str_starts_with($key, 'restore:'), 'merged' => $c['merged']];
            $summary['companies']++;
            if ($c['id'] === null) {
                $summary['accounts_created']++;
            } elseif ($fills !== [] || $plan[$key]['restore']) {
                $summary['accounts_updated']++;
            }
            $summary['accounts_merged'] += count($c['merged']);
        }
        $toCreate = array_values(array_filter(array_map(fn ($p) => $p['id'] === null ? $p['name'] : null, $plan)));
        $toUpdate = array_values(array_filter(array_map(fn ($p) => ($p['id'] !== null && ($p['fills'] !== [] || $p['restore'])) ? ($p['name'] ?? ($accountNames[$p['id']] ?? null)) : null, $plan)));
        $toMerge = array_values(array_map(fn ($dup) => (string) $accountNames[$dup], array_keys($merge)));

        if ($commit && ($work !== [] || $plan !== [])) {
            // Counts of what really happened replace the plan's counts.
            $summary['accounts_created'] = 0;
            $summary['accounts_updated'] = 0;
            $summary['accounts_merged'] = 0;
            DB::transaction(function () use ($tenant, $userId, $work, $plan, $scope, $merge, &$summary) {
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
                // duplicates: their contacts move to the company, then they are removed
                foreach ($merge as $dup => $survivor) {
                    GtmContact::where('sub_institute_id', $tenant)->where('account_id', $dup)->update(['account_id' => $survivor]);
                    GtmAccount::where('sub_institute_id', $tenant)->whereKey($dup)->first()?->delete();
                    $summary['accounts_merged']++;
                }
                foreach ($work as $w) {
                    if ($w['kind'] === 'update') {
                        GtmContact::where('sub_institute_id', $tenant)->whereKey($w['id'])->first()?->fill($w['fills'])->save();

                        continue;
                    }
                    $accountId = $w['account_id'] ?? ($w['company_key'] !== null ? ($ids[$w['company_key']] ?? null) : null);
                    if ($accountId === null) {
                        continue;
                    }
                    $c = $w['contact'];
                    GtmContact::create([
                        'sub_institute_id' => $tenant, 'account_id' => $accountId, 'full_name' => $c['name'], 'title' => $c['title'], 'email' => $c['email'],
                        'phone' => $c['phone'], 'linkedin_url' => $c['linkedin'], 'role_in_deal' => $c['role'], 'status' => 'active', 'source' => 'import',
                        'notes' => $this->joinLines($c['lines']), 'created_by' => $userId,
                    ]);
                    $summary['imported']++;
                }
            });
            GtmAudit::record('gtm.contacts.imported', $tenant, 'gtm_contacts', null, $userId, ['summary' => $summary]);
        }

        return ['summary' => $summary, 'rows' => array_slice($out, 0, 300), 'accounts_to_create' => $toCreate, 'accounts_to_update' => $toUpdate, 'accounts_to_merge' => $toMerge];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** A person who appears twice in one file is one contact: the later row only adds what the earlier one did not say. */
    private function foldIntoPending(array &$work, ?int $index, array $contact): void
    {
        if ($index === null || ! isset($work[$index])) {
            return;
        }
        $w = &$work[$index];
        $c = $w['contact'];
        foreach (['title', 'phone', 'linkedin', 'role'] as $key) {
            if ($c[$key] === null && $contact[$key] !== null) {
                $c[$key] = $contact[$key];
            }
        }
        foreach ($contact['lines'] as $line) {
            if (! in_array($line, $c['lines'], true)) {
                $c['lines'][] = $line;
            }
        }
        $w['contact'] = $c;
        if ($w['kind'] === 'update') {
            $w['fills'] = $this->contactFills($w['found'], $c);
        }
    }

    /** Is this an import-made duplicate that nothing else points to? Only then is it merged and removed. */
    private function mergeable(int $tenant, object $account): bool
    {
        if (($account->source ?? null) !== 'import' || ! empty($account->crm_organization_id ?? null) || ! empty($account->company_id ?? null)) {
            return false;
        }
        foreach ($this->tablesPointingAtAccounts() as $table) {
            if (DB::table($table)->where('account_id', $account->id)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the other gtm tables that have an account_id (deals, activities, renewals ...), apart from contacts, which move */
    private function tablesPointingAtAccounts(): array
    {
        $tables = $this->pointers;
        if ($tables === null) {
            $driver = DB::connection()->getDriverName();
            $names = $driver === 'sqlite'
                ? array_map(fn ($r) => $r->name, DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'gtm\\_%' ESCAPE '\\'"))
                : array_map(fn ($r) => (string) array_values((array) $r)[0], DB::select("SHOW TABLES LIKE 'gtm\\_%'"));
            $tables = array_values(array_filter($names, fn ($t) => ! in_array($t, ['gtm_accounts', 'gtm_contacts'], true) && ColumnExists::has($t, 'account_id')));
            $this->pointers = $tables;
        }

        return $tables;
    }

    /** Every email in a cell ("a@x.com (alt: b@x.com)", "a@x.com; b@x.com"): the first is the contact's, the rest are kept in the notes. @return array{primary: ?string, others: list<string>, all: list<string>} */
    private function emails(string $cell): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $cell, $m);
        $all = array_values(array_unique(array_map('mb_strtolower', $m[0])));

        return ['primary' => $all[0] ?? null, 'others' => array_slice($all, 1), 'all' => $all];
    }

    /** "+91 9104 (alt: +91 8200)", "079-1 / 7203" -> the first number in the field, the rest in the notes. @return array{primary: ?string, others: list<string>} */
    private function phones(string $cell): array
    {
        $parts = preg_split('/\s*(?:\/|;|\(\s*alt:|\balt:)\s*/i', $cell) ?: [];
        $parts = array_values(array_filter(array_map(fn ($p) => trim($p, " \t\n\r\0\x0B)(,"), $parts), fn ($p) => $p !== ''));

        return ['primary' => $parts[0] ?? null, 'others' => array_slice($parts, 1)];
    }

    /** @return array{value: string, exact: bool} the buying role, from what was written ("Decision maker (Directors) / General contact" is a decision maker; the words are kept in the notes) */
    private function role(string $text): array
    {
        $t = str_replace([' ', '-'], '_', mb_strtolower(trim($text)));
        if ($t === '') {
            return ['value' => '', 'exact' => true];
        }
        if (in_array($t, self::ROLES, true)) {
            return ['value' => $t, 'exact' => true];
        }
        foreach (['decision' => 'decision_maker', 'economic' => 'economic_buyer', 'champion' => 'champion', 'influenc' => 'influencer', 'blocker' => 'blocker'] as $word => $role) {
            if (str_contains($t, $word)) {
                return ['value' => $role, 'exact' => false];
            }
        }

        return ['value' => '', 'exact' => false];
    }

    private function joinLines(array $lines): ?string
    {
        $text = trim(implode("\n", array_values(array_unique(array_filter($lines)))));

        return $text === '' ? null : mb_substr($text, 0, 4000);
    }

    /** What an existing contact is missing that this row knows: only empty fields are filled; notes only gain lines they do not already contain. @return array<string, string> */
    private function contactFills(object $existing, array $contact): array
    {
        $fills = [];
        foreach (['title' => 'title', 'phone' => 'phone', 'linkedin_url' => 'linkedin', 'role_in_deal' => 'role'] as $column => $key) {
            if ($contact[$key] !== null && trim((string) ($existing->{$column} ?? '')) === '') {
                $fills[$column] = $contact[$key];
            }
        }
        $notes = trim((string) $existing->notes);
        $merged = $notes;
        foreach ($contact['lines'] as $line) {
            if (! str_contains($merged, $line)) {
                $merged = $merged === '' ? $line : $merged."\n".$line;
            }
        }
        if ($merged !== $notes) {
            $fills['notes'] = mb_substr($merged, 0, 4000);
        }

        return $fills;
    }

    /** The fields of an existing account that the file can fill: only the empty ones, never an overwrite. @return array<string, mixed> */
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
     * The other columns of the file, kept under their own headings: each heading keeps what it had and gains each new value.
     *
     * @param  array<string, mixed>|string|null  $current
     * @param  array<string, list<string>>  $fromFile
     * @return array<string, string>
     */
    private function mergedDetails($current, array $fromFile): array
    {
        $details = is_array($current) ? $current : (is_string($current) && $current !== '' ? (json_decode($current, true) ?: []) : []);
        foreach ($fromFile as $heading => $values) {
            $text = trim((string) ($details[$heading] ?? ''));
            foreach ($values as $v) {
                if (! str_contains($text, $v)) {
                    $text = $text === '' ? $v : $text."\n".$v;
                }
            }
            if ($text !== '') {
                $details[$heading] = mb_substr($text, 0, 4000);
            }
        }

        return $details;
    }

    /**
     * The city a note opens with ("Ahmedabad. HR strategy ..." / "Pune | Sections A ..."), but ONLY when the location data knows it as a city of
     * the chosen country (and state). A word that merely starts a note ("Website only.") is never taken for a place.
     */
    private function cityFromNote(string $note, array $scope): ?string
    {
        $region = $scope['region'] ?? null;
        if ($region === null || ! preg_match('/^\s*([\p{L}][\p{L} .\'-]{1,40}?)\s*(?:[.|,;:\n\/]|$)/u', $note, $m)) {
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
