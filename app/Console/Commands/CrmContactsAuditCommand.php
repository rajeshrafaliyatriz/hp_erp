<?php

namespace App\Console\Commands;

use App\Domain\Crm\Contacts\ContactReferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A read-only look at the people of every company (S18, 25 Nov 2026; contract Docs/agentic-crm/slices/S18-CONTACTS-INTO-ORGANIZATIONS-CONTRACT.md section 2).
 *
 *   php artisan crm:contacts-audit                 prints, SELECT statements only:
 *     1. contacts per organisation (the totals per organisation, and the companies with the most people)
 *     2. contacts with no company
 *     3. duplicate e-mail addresses inside one company
 *     4. for every column ContactReferences registers as holding a contact id: how many rows point at a contact, and how many of those point at a contact that is missing or in the Recycle Bin
 *     5. the schema scan: columns whose name looks like a contact reference but are neither registered nor allow-listed (what the merge would leave behind)
 *   --tenant=<id>    one organisation only (items 1 to 4)
 *   --top=<n>        how many companies to list in item 1 (default 15)
 *
 * It never writes: it has no --apply, and passing one is refused. Without --connection it runs only on the dev host (or the sqlite test database); any other host must be named.
 * A pointer is "bad" when no contact has that id or the contact is soft-deleted; a pointer to a live contact of ANOTHER organisation is not detected here.
 *
 * Exit code: 0 printed, 2 the command could not run (bad option, refused host, --apply).
 */
class CrmContactsAuditCommand extends Command
{
    protected $signature = 'crm:contacts-audit
        {--tenant= : One organisation only}
        {--top=15 : How many companies to list by number of people}
        {--apply : Refused: this command only reads}
        {--connection= : Connection name. Without it only the dev connection is used; any other host must be named here}';

    protected $description = 'Read-only: contacts per organisation, contacts with no company, duplicate e-mails in a company, pointer rows per registered column and pointers to a missing or deleted contact, unregistered contact columns.';

    /** @var array<string, list<string>> table => its columns (lower case), read once */
    private array $columns = [];

    public function handle(): int
    {
        if ($this->option('apply')) {
            $this->error('crm:contacts-audit only reads: there is nothing to apply. Nothing was done.');

            return self::INVALID;
        }
        $explicit = $this->option('connection');
        $explicit = is_string($explicit) && $explicit !== '' ? $explicit : null;
        $refusal = ProductMasterIsolateTenantsCommand::refusal($explicit, (string) config('database.default'), (array) config('database.connections'));
        if ($refusal !== null) {
            $this->error($refusal);

            return self::INVALID;
        }
        $only = $this->option('tenant');
        if ($only !== null && $only !== '' && (! ctype_digit((string) $only) || (int) $only <= 0)) {
            $this->error('--tenant must be a whole number above 0 (an organisation id).');

            return self::INVALID;
        }
        $only = $only === null || $only === '' ? null : (int) $only;
        $top = max(1, min(200, (int) $this->option('top')));
        $db = DB::connection($explicit);
        $schema = Schema::connection($explicit);

        if (! $schema->hasTable('crm_contacts')) {
            $this->error('This connection has no crm_contacts table. Nothing was done.');

            return self::INVALID;
        }

        $this->line('crm:contacts-audit - READ ONLY, on connection ' . ($explicit ?? config('database.default')) . ($only !== null ? " (organisation {$only})" : ''));

        $this->perOrganisation($db, $only, $top);
        $this->duplicateEmails($db, $only);
        $this->pointers($db, $schema, $only);
        $this->schemaScan($db);

        return self::SUCCESS;
    }

    private function live($db, ?int $only)
    {
        $q = $db->table('crm_contacts')->whereNull('deleted_at');

        return $only === null ? $q : $q->where('sub_institute_id', $only);
    }

    private function perOrganisation($db, ?int $only, int $top): void
    {
        $this->line('');
        $this->line('1. Contacts per organisation (live contacts)');
        $tenants = $this->live($db, $only)->groupBy('sub_institute_id')->orderBy('sub_institute_id')
            ->selectRaw('sub_institute_id, COUNT(*) as n, SUM(CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END) as no_company')->get();
        if ($tenants->isEmpty()) {
            $this->line('   no contacts');
        }
        foreach ($tenants as $t) {
            $this->line(sprintf('   organisation %d: %d contact(s), %d with a company, %d with no company', $t->sub_institute_id, $t->n, $t->n - (int) $t->no_company, (int) $t->no_company));
        }

        $this->line('   companies with the most people (top ' . $top . '):');
        $q = $db->table('crm_contacts as c')->leftJoin('crm_organizations as o', 'o.id', '=', 'c.organization_id')->whereNull('c.deleted_at')->whereNotNull('c.organization_id');
        if ($only !== null) {
            $q->where('c.sub_institute_id', $only);
        }
        // A host that has not run the S18 migration yet has no star column: every company then has no primary.
        $stars = in_array('is_primary', $this->columnsOf($db, 'crm_contacts'), true) ? 'SUM(CASE WHEN c.is_primary = 1 THEN 1 ELSE 0 END)' : '0';
        $rows = $q->groupBy('c.sub_institute_id', 'c.organization_id', 'o.name')->orderByRaw('COUNT(*) DESC')->orderBy('c.organization_id')->limit($top)
            ->selectRaw("c.sub_institute_id, c.organization_id, o.name, COUNT(*) as n, {$stars} as primaries")->get();
        foreach ($rows as $r) {
            $this->line(sprintf('     %4d  %s (id %d, organisation %d)%s', $r->n, $r->name ?? '(company not found)', $r->organization_id, $r->sub_institute_id,
                (int) $r->primaries > 1 ? '  <- ' . $r->primaries . ' primaries' : ''));
        }

        $this->line('2. Contacts with no company');
        $none = $this->live($db, $only)->whereNull('organization_id')->count();
        $this->line("   {$none} contact(s) have no company");
    }

    private function duplicateEmails($db, ?int $only): void
    {
        $this->line('3. Duplicate e-mail addresses inside one company');
        $q = $this->live($db, $only)->whereNotNull('organization_id')->whereNotNull('email')->where('email', '<>', '');
        $rows = $q->groupBy('sub_institute_id', 'organization_id')->groupByRaw('LOWER(TRIM(email))')->havingRaw('COUNT(*) > 1')->orderByRaw('COUNT(*) DESC')->limit(50)
            ->selectRaw('sub_institute_id, organization_id, LOWER(TRIM(email)) as e, COUNT(*) as n')->get();
        if ($rows->isEmpty()) {
            $this->line('   none');
        }
        foreach ($rows as $r) {
            $this->line(sprintf('   %s is on %d contacts of company %d (organisation %d)', $r->e, $r->n, $r->organization_id, $r->sub_institute_id));
        }
    }

    private function pointers($db, $schema, ?int $only): void
    {
        $this->line('4. Rows that point at a contact, per registered column (rows / pointing at a missing or deleted contact)');
        $bad = 0;
        foreach ($this->references() as $ref) {
            $table = $ref['table'];
            $column = $ref['column'];
            $needed = array_merge([$column], $ref['type'] !== null ? [$ref['type']] : [], $ref['tenant'] !== null ? [$ref['tenant']] : []);
            if (! $schema->hasTable($table) || array_diff($needed, $this->columnsOf($db, $table)) !== []) {
                $this->line(sprintf('   %-50s (not on this host)', "{$table}.{$column}"));

                continue;
            }
            $q = $db->table("{$table} as t")->leftJoin('crm_contacts as c', 'c.id', '=', "t.{$column}")->whereNotNull("t.{$column}");
            if ($ref['type'] !== null) {
                $q->whereIn("t.{$ref['type']}", $ref['values']);
            }
            if ($ref['self']) {
                $q->whereNull('t.deleted_at');
            }
            if ($only !== null && $ref['tenant'] !== null) {
                $q->where("t.{$ref['tenant']}", $only);
            }
            $r = $q->selectRaw('COUNT(*) as total, SUM(CASE WHEN c.id IS NULL OR c.deleted_at IS NOT NULL THEN 1 ELSE 0 END) as bad')->first();
            $bad += (int) $r->bad;
            $this->line(sprintf('   %-50s %6d / %d', "{$table}.{$column}" . ($ref['type'] !== null ? " [{$ref['values'][0]}]" : ''), (int) $r->total, (int) $r->bad));
        }
        $this->line($bad === 0 ? '   no pointer to a missing or deleted contact' : "   {$bad} pointer(s) to a missing or deleted contact (a merge that was run before ContactReferences existed leaves these)");
    }

    private function schemaScan($db): void
    {
        $this->line('5. Columns that look like a contact reference and are neither registered nor allow-listed');
        $found = [];
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $rows = $db->table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where(fn ($q) => $q->where('COLUMN_NAME', 'like', '%contact%')->orWhere('COLUMN_NAME', 'reports_to_id')->orWhere('COLUMN_NAME', 'like', '%\\_type')->orWhere('COLUMN_NAME', 'record_table'))
                ->get(['TABLE_NAME', 'COLUMN_NAME']);
            foreach ($rows as $r) {
                $found[] = ['table' => (string) $r->TABLE_NAME, 'column' => (string) $r->COLUMN_NAME];
            }
        } else {
            foreach ($db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
                foreach ($this->columnsOf($db, (string) $t->name) as $column) {
                    $found[] = ['table' => (string) $t->name, 'column' => $column];
                }
            }
        }

        $missing = ContactReferences::unregistered($found);
        if ($missing === []) {
            $this->line('   none: every column that looks like a contact reference is registered or allow-listed with a reason');
        }
        foreach ($missing as $key) {
            $this->line("   {$key}");
        }
    }

    /**
     * One entry per registered column.
     *
     * @return list<array{table: string, column: string, type: ?string, values: list<string>, tenant: ?string, self: bool}>
     */
    private function references(): array
    {
        $out = [];
        foreach (ContactReferences::direct() as $d) {
            $out[] = ['table' => $d['table'], 'column' => $d['column'], 'type' => null, 'values' => [], 'tenant' => $d['scope'], 'self' => $d['self_reference'] ?? false];
        }
        foreach (ContactReferences::junctions() as $j) {
            $out[] = ['table' => $j['table'], 'column' => $j['column'], 'type' => null, 'values' => [], 'tenant' => null, 'self' => false];
        }
        foreach (ContactReferences::polymorphic() as $p) {
            $out[] = ['table' => $p['table'], 'column' => $p['id'], 'type' => $p['type'], 'values' => $p['values'], 'tenant' => is_string($p['scope']) ? $p['scope'] : null, 'self' => false];
        }

        return $out;
    }

    /** @return list<string> the columns of a table, lower case, read once (SHOW COLUMNS works on every MariaDB version; the schema builder's listing does not on 10.1) */
    private function columnsOf($db, string $table): array
    {
        return $this->columns[$table] ??= in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            ? array_map(fn ($c) => strtolower((string) $c->Field), $db->select('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`'))
            : array_map('strtolower', Schema::connection($db->getName())->getColumnListing($table));
    }
}
