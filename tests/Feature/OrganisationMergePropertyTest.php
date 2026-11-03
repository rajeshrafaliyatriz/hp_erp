<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrganisationMerger;
use App\Domain\OrgAccounts\OrganisationReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property test for the organisation merge (slice 3, decision D6, validator 4 of the slice file): seeded random worlds built FROM THE REGISTRY, one minimal table per
 * registry entry, several tenants, several organisations per tenant, random referencing rows (some already on the survivor, some of other tenants carrying the very
 * same organisation ids, some with a type that is not an organisation), a random pair merged in one tenant, and then the invariants checked row by row:
 *
 *   1. nothing in the tenant references the removed organisation in ANY registry entry;
 *   2. the only rows that disappear are duplicates the survivor already had (or an edge between the two merged organisations), and their number equals the reported count;
 *   3. every other row that referenced the removed organisation references the survivor now and is otherwise identical;
 *   4. no row of another tenant, and no row that never referenced the organisation, changed at all;
 *   5. merging the same pair again changes nothing and reports zeros.
 *
 * The expected state is computed here from the rows, not by calling the merger. Every iteration has its own seed (printed in any failure) so a failing world can be
 * rebuilt exactly: mt_srand(BASE_SEED + iteration). Coverage counters at the end fail the test if the generator stopped producing the situations it exists to test
 * (a property test that never meets a duplicate proves nothing). In-memory sqlite only.
 */
class OrganisationMergePropertyTest extends TestCase
{
    private const ITERATIONS = 250;

    private const BASE_SEED = 20261109;

    private const TENANTS = [1, 2, 3];

    /** The registry's `unique` for these is a deduplication rule, not a database key: the real table has no unique index. */
    private const NO_DB_KEY = ['crm_entity_relationships'];

    /**
     * Every reference point known when the registry was written, listed here by hand (from the migrations and from what each writer stores). The worlds below are built
     * FROM the registry, so a reference deleted from it would simply vanish from the world and the property would still hold: this list is the independent anchor.
     * New references may be added to the registry freely; losing one of these, or changing the value a table stores for an organisation, fails here.
     */
    private const KNOWN_DIRECT = [
        'crm_contacts.organization_id', 'crm_leads.converted_organization_id', 'crm_organizations.parent_id', 'crm_opportunities.organization_id', 'crm_quotes.organization_id',
        'crm_sales_orders.organization_id', 'crm_purchase_orders.organization_id', 'crm_invoices.organization_id', 'crm_service_contracts.organization_id', 'crm_assets.organization_id',
        'crm_tickets.organization_id', 'crm_organization_comments.organization_id', 'gtm_accounts.crm_organization_id', 'task_management_projects.crm_organization_id',
        'crm_signal_inbox.linked_account_id', 'g2g_org_accounts.crm_organization_id',
    ];

    /** @var list<string> "table.idColumn by typeColumn = value" */
    private const KNOWN_POLYMORPHIC = [
        'crm_campaign_targets.target_id by target_type = organization', 'crm_email_links.linked_id by linked_type = ORGANIZATION', 'document_library_links.linked_id by linked_type = ORGANIZATION',
        'task_management_calendar_events.linked_id by linked_type = ORGANIZATION', 'crm_sms_log.related_id by related_type = organization',
        'crm_outreach_approvals.target_id by target_type = organization', 'crm_knowledge_sources.entity_id by entity_type = organization',
        'crm_consent_preferences.entity_id by entity_type = organization', 'crm_entity_relationships.from_id by from_type = organization',
        'crm_entity_relationships.to_id by to_type = organization', 'g2g_custom_field_values.record_id by record_table = crm_organizations',
    ];

    /** @var array<string, array{tenant: ?string, via: ?array<string, string>, cols: array<string, string>, keys: list<list<string>>}> */
    private array $spec = [];

    /** @var array<string, int> how often each situation was generated */
    private array $coverage = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = $this->specFromRegistry();
        foreach ($this->spec as $table => $s) {
            Schema::create($table, function ($b) use ($s) {
                $b->unsignedBigInteger('id')->primary();
                if ($s['tenant'] !== null) {
                    $b->unsignedBigInteger($s['tenant'])->nullable();
                }
                foreach ($s['cols'] as $column => $kind) {
                    match ($kind) {
                        'int' => $b->unsignedBigInteger($column)->nullable(),
                        'time' => $b->timestamp($column)->nullable(),
                        default => $b->string($column, 64)->nullable(),
                    };
                }
                foreach ($s['keys'] as $key) {
                    $b->unique($key);
                }
            });
        }
    }

    /** A minimal table per registry entry: the id and type columns, the tenant column and the columns of the unique key. */
    private function specFromRegistry(): array
    {
        $spec = [];
        $table = function (string $name) use (&$spec): void {
            $spec[$name] ??= ['tenant' => null, 'via' => null, 'cols' => [], 'keys' => []];
        };
        $kindOf = fn (string $column): string => str_ends_with($column, '_id') ? 'int' : 'string';

        foreach (OrganisationReferences::direct() as $d) {
            $table($d['table']);
            $spec[$d['table']]['tenant'] = $d['scope'];
            $spec[$d['table']]['cols'][$d['column']] = 'int';
        }
        foreach (OrganisationReferences::polymorphic() as $p) {
            $table($p['table']);
            $spec[$p['table']]['cols'][$p['id']] = 'int';
            $spec[$p['table']]['cols'][$p['type']] = 'string';
            foreach (array_merge($p['unique'], array_values($p['pair'] ?? [])) as $column) {
                $spec[$p['table']]['cols'][$column] ??= $kindOf($column);
            }
            if (is_string($p['scope'])) {
                $spec[$p['table']]['tenant'] = $p['scope'];
            } else {
                $spec[$p['table']]['via'] = $p['scope'];
                $table($p['scope']['parent']);
                $spec[$p['scope']['parent']]['tenant'] = $p['scope']['parent_tenant'];
            }
            if ($p['unique'] !== [] && ! in_array($p['table'], self::NO_DB_KEY, true)) {
                $spec[$p['table']]['keys'][] = array_merge([$p['type'], $p['id']], $p['unique']);
            }
        }

        $identity = OrganisationReferences::identity();
        $table($identity['table']);
        $spec[$identity['table']]['tenant'] = $identity['scope'];
        foreach (['crm_organization_id', 'gtm_account_id', 'g2g_company_id'] as $pointer) {
            $spec[$identity['table']]['cols'][$pointer] = 'int';
            $spec[$identity['table']]['keys'][] = [$identity['scope'], $pointer];
        }
        foreach (['gtm_link_basis', 'company_link_basis'] as $basis) {
            $spec[$identity['table']]['cols'][$basis] = 'string';
        }
        $spec[$identity['table']]['cols']['deleted_at'] = 'time';
        $spec[$identity['table']]['cols']['updated_at'] = 'time';

        return $spec;
    }

    // ── the property ─────────────────────────────────────────────────────────

    public function test_the_registry_still_lists_every_reference_point_known_when_it_was_written_with_the_value_each_table_stores(): void
    {
        $direct = array_map(fn ($c) => "{$c['table']}.{$c['column']}", array_merge(OrganisationReferences::direct(), [OrganisationReferences::identity()]));
        $this->assertSame([], array_values(array_diff(self::KNOWN_DIRECT, $direct)), 'direct references missing from OrganisationReferences::direct()');

        $polymorphic = [];
        foreach (OrganisationReferences::polymorphic() as $p) {
            foreach ($p['values'] as $value) {
                $polymorphic[] = "{$p['table']}.{$p['id']} by {$p['type']} = {$value}";
            }
        }
        $this->assertSame([], array_values(array_diff(self::KNOWN_POLYMORPHIC, $polymorphic)), 'polymorphic references (or the exact value a table stores for an organisation: upper or lower case) missing from OrganisationReferences::polymorphic()');
    }

    public function test_after_a_merge_nothing_references_the_removed_organisation_and_nothing_else_moves(): void
    {
        $this->coverage = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $seed = self::BASE_SEED + $i;
            foreach (array_keys($this->spec) as $table) {
                DB::table($table)->delete();
            }
            $world = $this->buildWorld($seed);
            $ctx = "seed {$seed}: merging organisation {$world['from']} into {$world['to']} in tenant {$world['tenant']}";

            $before = $this->snapshot();
            $result = DB::transaction(fn () => OrganisationMerger::repoint($world['tenant'], $world['from'], $world['to']));
            $after = $this->snapshot();

            $this->checkInvariants($ctx, $world, $before, $after, $result);

            $again = DB::transaction(fn () => OrganisationMerger::repoint($world['tenant'], $world['from'], $world['to']));
            $this->assertSame(['moved' => [], 'dropped' => [], 'cleared' => []], $again, "{$ctx}: merging the same pair again must report nothing");
            $this->assertSame($after, $this->snapshot(), "{$ctx}: merging the same pair again must change nothing");
        }

        // The property is only worth something if the generator really produced the situations it is about.
        foreach (['duplicate_row_deleted', 'self_edge_deleted', 'row_moved', 'other_tenant_row_with_same_org', 'wrong_type_row_with_same_id', 'survivor_was_child', 'child_reparented',
            'identity_both', 'identity_loser_only', 'identity_pointer_carried', 'identity_other_kept_alive', 'soft_deleted_identity_involved'] as $situation) {
            $this->assertGreaterThan(0, $this->coverage[$situation] ?? 0, "the generator never produced: {$situation} (in " . self::ITERATIONS . ' worlds)');
        }
        foreach (array_keys($this->spec) as $table) {
            if (! in_array($table, ['crm_campaigns', OrganisationReferences::identity()['table']], true)) {
                $this->assertGreaterThan(0, $this->coverage["moved:{$table}"] ?? 0, "no row of {$table} was ever moved: its registry entry is untested");
            }
        }
    }

    // ── generating a world ───────────────────────────────────────────────────

    /** @param list<mixed> $items */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function chance(float $p): bool
    {
        return mt_rand(0, 999999) / 1000000 < $p;
    }

    private function bump(string $situation): void
    {
        $this->coverage[$situation] = ($this->coverage[$situation] ?? 0) + 1;
    }

    /** @return list<int> */
    private function otherTenants(int $tenant): array
    {
        return array_values(array_diff(self::TENANTS, [$tenant]));
    }

    /**
     * @return array{tenant: int, from: int, to: int, orgs: array<int, list<int>>, campaignTenant: array<int, int>}
     */
    private function buildWorld(int $seed): array
    {
        mt_srand($seed);
        $orgs = [];
        foreach (self::TENANTS as $t) {
            $copy = range(1, 9);
            $mine = [];
            for ($k = mt_rand(3, 5); $k > 0; $k--) {
                $mine[] = array_splice($copy, mt_rand(0, count($copy) - 1), 1)[0];
            }
            $orgs[$t] = $mine;
        }
        $tenant = $this->pick(self::TENANTS);
        $rest = $orgs[$tenant];
        $from = array_splice($rest, mt_rand(0, count($rest) - 1), 1)[0];
        $world = ['tenant' => $tenant, 'from' => $from, 'to' => $this->pick($rest), 'orgs' => $orgs, 'campaignTenant' => []];

        // Campaigns, so a campaign target (which has no tenant column of its own) is scoped through its campaign.
        foreach (self::TENANTS as $t) {
            foreach ([1, 2] as $k) {
                $world['campaignTenant'][$t * 10 + $k] = $t;
                DB::table('crm_campaigns')->insert(['id' => $t * 10 + $k, 'sub_institute_id' => $t]);
            }
        }

        foreach (array_keys($this->spec) as $table) {
            match ($table) {
                'crm_campaigns' => null,
                OrganisationReferences::identity()['table'] => $this->identityRows($world),
                'crm_organizations' => $this->organisationRows($world),
                'crm_entity_relationships' => $this->edgeRows($world),
                default => $this->plainRows($table, $world),
            };
        }

        return $world;
    }

    /** An organisation id for a row of this tenant: mostly the tenant's own, often the pair being merged (also for OTHER tenants: ids are plain numbers, so coincidences happen), sometimes nothing. */
    private function orgRef(array $world, int $tenant, bool $nullable = true): ?int
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 45 => $this->pick($world['orgs'][$tenant]),
            $r <= 62 => $world['from'],
            $r <= 79 => $world['to'],
            $r <= 88 && $nullable => null,
            default => 99,
        };
    }

    private function plainRows(string $table, array $world): void
    {
        $s = $this->spec[$table];
        $entry = $this->entries($table)[0] ?? null;
        $seen = [];

        for ($n = mt_rand(0, 8), $id = 0; $n > 0; $n--) {
            $tenant = $this->chance(0.55) ? $world['tenant'] : $this->pick($this->otherTenants($world['tenant']));
            $row = ['id' => ++$id];
            if ($s['tenant'] !== null) {
                $row[$s['tenant']] = $tenant;
            }
            if ($s['via'] !== null) {
                $row[$s['via']['via']] = $this->pick(array_keys($world['campaignTenant']));
                $tenant = $world['campaignTenant'][$row[$s['via']['via']]];
            }
            $refs = [];
            if ($entry === null) {
                foreach (array_keys($s['cols']) as $column) {
                    $refs[] = $row[$column] = $this->orgRef($world, $tenant);
                }
            } else {
                $row[$entry['type']] = $this->chance(0.72) ? $this->pick($entry['values']) : $this->otherType($entry['values'][0]);
                $refs[] = $row[$entry['id']] = $this->orgRef($world, $tenant, false);
                foreach ($entry['unique'] as $column) {
                    if ($column !== ($s['via']['via'] ?? null)) {
                        // Tenant-disjoint, as in real data (e-mail, document and field ids belong to one tenant): a coincidence of organisation ids across tenants never collides.
                        $row[$column] = str_ends_with($column, '_id') ? $tenant * 100 + mt_rand(1, 3) : 'v' . $tenant . '-' . mt_rand(1, 3);
                    }
                }
            }
            foreach ($s['keys'] as $key) {
                $fingerprint = $key[0] . ':' . implode('|', array_map(fn ($c) => (string) ($row[$c] ?? ''), $key));
                if (isset($seen[$fingerprint])) {
                    continue 2;
                }
                $seen[$fingerprint] = true;
            }
            DB::table($table)->insert($row);

            if ($tenant !== $world['tenant'] && array_intersect([$world['from'], $world['to']], $refs) !== []) {
                $this->bump('other_tenant_row_with_same_org');
            }
            if ($entry !== null && ! in_array($row[$entry['type']], $entry['values'], true) && $tenant === $world['tenant'] && $row[$entry['id']] === $world['from']) {
                $this->bump('wrong_type_row_with_same_id');
            }
        }
    }

    /** A type word that is not an organisation, in the same case convention as the table. */
    private function otherType(string $organisation): string
    {
        return match (true) {
            $organisation === 'crm_organizations' => $this->pick(['tbluser', 'crm_contacts']),
            $organisation === strtoupper($organisation) => $this->pick(['LEAD', 'CONTACT', 'OPPORTUNITY']),
            default => $this->pick(['lead', 'contact', 'opportunity']),
        };
    }

    private function organisationRows(array $world): void
    {
        // The pair itself (organisation rows are real rows with their own primary key; the survivor is sometimes a child of the removed one, and the other way round),
        // then other organisations, possibly of other tenants, parented to either of them.
        DB::table('crm_organizations')->insert(['id' => $world['from'], 'sub_institute_id' => $world['tenant'], 'parent_id' => $this->chance(0.2) ? $world['to'] : null]);
        DB::table('crm_organizations')->insert(['id' => $world['to'], 'sub_institute_id' => $world['tenant'], 'parent_id' => $this->chance(0.3) ? $world['from'] : $this->pick([null, 99])]);
        for ($n = mt_rand(0, 6), $id = 100; $n > 0; $n--) {
            $t = $this->chance(0.55) ? $world['tenant'] : $this->pick($this->otherTenants($world['tenant']));
            DB::table('crm_organizations')->insert(['id' => ++$id, 'sub_institute_id' => $t, 'parent_id' => $this->orgRef($world, $t)]);
        }
    }

    private function edgeRows(array $world): void
    {
        $seen = [];
        for ($n = mt_rand(0, 8), $id = 0; $n > 0; $n--) {
            $tenant = $this->chance(0.55) ? $world['tenant'] : $this->pick($this->otherTenants($world['tenant']));
            $row = ['id' => ++$id, 'sub_institute_id' => $tenant, 'relationship_type' => $this->pick(['partner_of', 'influenced_by'])];
            foreach (['from', 'to'] as $end) {
                $isOrganisation = $this->chance(0.7);
                $row["{$end}_type"] = $isOrganisation ? 'organization' : $this->pick(['contact', 'lead']);
                $row["{$end}_id"] = $isOrganisation ? $this->orgRef($world, $tenant, false) : mt_rand(1, 9);
            }
            // An organisation related to itself is not a world the merge is asked to repair.
            if ($row['from_type'] === 'organization' && $row['to_type'] === 'organization' && $row['from_id'] === $row['to_id']) {
                continue;
            }
            $fingerprint = implode('|', [$tenant, $row['from_type'], $row['from_id'], $row['to_type'], $row['to_id'], $row['relationship_type']]);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            DB::table('crm_entity_relationships')->insert($row);
        }
        // An edge between the two merged organisations, which would relate the survivor to itself once moved.
        if ($this->chance(0.4)) {
            [$a, $b] = $this->chance(0.5) ? [$world['from'], $world['to']] : [$world['to'], $world['from']];
            DB::table('crm_entity_relationships')->insert(['id' => 90, 'sub_institute_id' => $world['tenant'], 'from_type' => 'organization', 'from_id' => $a,
                'to_type' => 'organization', 'to_id' => $b, 'relationship_type' => 'partner_of']);
        }
    }

    private function identityRows(array $world): void
    {
        $table = OrganisationReferences::identity()['table'];
        $seen = [];
        $id = 0;
        $make = function (int $tenant, ?int $crm) use ($table, &$seen, &$id): void {
            $row = ['id' => ++$id, 'sub_institute_id' => $tenant, 'crm_organization_id' => $crm, 'gtm_account_id' => $this->chance(0.6) ? mt_rand(1, 6) : null,
                'g2g_company_id' => $this->chance(0.6) ? mt_rand(1, 6) : null, 'deleted_at' => $this->chance(0.25) ? '2026-01-01 00:00:00' : null];
            foreach (['crm_organization_id', 'gtm_account_id', 'g2g_company_id'] as $pointer) {
                if ($row[$pointer] !== null && isset($seen[$pointer][$tenant][$row[$pointer]])) {
                    $row[$pointer] = null;
                } elseif ($row[$pointer] !== null) {
                    $seen[$pointer][$tenant][$row[$pointer]] = true;
                }
            }
            DB::table($table)->insert($row);
        };

        if ($this->chance(0.6)) {
            $make($world['tenant'], $world['from']);
        }
        if ($this->chance(0.6)) {
            $make($world['tenant'], $world['to']);
        }
        foreach (self::TENANTS as $t) {
            for ($n = mt_rand(0, 3); $n > 0; $n--) {
                $make($t, $this->orgRef($world, $t));
            }
        }
    }

    // ── checking ─────────────────────────────────────────────────────────────

    /** @return array<string, array<int, array<string, ?string>>> table => id => row, every value as string or null */
    private function snapshot(): array
    {
        $all = [];
        foreach (array_keys($this->spec) as $table) {
            $all[$table] = [];
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $all[$table][(int) $row->id] = array_map(fn ($v) => $v === null ? null : (string) $v, (array) $row);
            }
        }

        return $all;
    }

    private function inScope(string $table, array $row, array $world): bool
    {
        $s = $this->spec[$table];
        if ($s['tenant'] !== null) {
            return (int) $row[$s['tenant']] === $world['tenant'];
        }

        return ($world['campaignTenant'][(int) $row[$s['via']['via']]] ?? null) === $world['tenant'];
    }

    /** @return list<array<string, mixed>> the polymorphic registry entries of a table */
    private function entries(string $table): array
    {
        return array_values(array_filter(OrganisationReferences::polymorphic(), fn ($p) => $p['table'] === $table));
    }

    private function referencesOrganisation(array $entry, array $row, int $organisation): bool
    {
        return in_array($row[$entry['type']], $entry['values'], true) && $row[$entry['id']] !== null && (int) $row[$entry['id']] === $organisation;
    }

    private function checkInvariants(string $ctx, array $world, array $before, array $after, array $result): void
    {
        $from = $world['from'];
        $to = $world['to'];
        $identityTable = OrganisationReferences::identity()['table'];

        foreach (array_keys($this->spec) as $table) {
            if ($table === $identityTable) {
                $this->checkIdentity($ctx, $world, $before[$table], $after[$table]);

                continue;
            }
            $direct = array_values(array_filter(OrganisationReferences::direct(), fn ($d) => $d['table'] === $table));
            $entries = $this->entries($table);
            $dropped = 0;
            $movedBy = [];

            $this->assertSame([], array_keys(array_diff_key($after[$table], $before[$table])), "{$ctx}: {$table} gained a row");

            foreach ($before[$table] as $id => $row) {
                $scoped = $this->inScope($table, $row, $world);
                $expected = $row;
                $moves = [];
                $reason = null;
                foreach ($direct as $d) {
                    if ($scoped && $row[$d['column']] !== null && (int) $row[$d['column']] === $from) {
                        if (($d['self_reference'] ?? false) && $id === $to) {
                            $expected[$d['column']] = null;
                            $this->bump('survivor_was_child');
                        } else {
                            $expected[$d['column']] = (string) $to;
                            $moves[] = "{$table}.{$d['column']}";
                            $this->bump('child_reparented');
                        }
                    }
                }
                foreach ($entries as $e) {
                    if ($scoped && $this->referencesOrganisation($e, $row, $from)) {
                        $expected[$e['id']] = (string) $to;
                        $moves[] = "{$table}.{$e['id']}";
                        $reason ??= $this->deletionReason($e, $row, $world, $before[$table]);
                    }
                }
                $referenced = $expected !== $row;

                if (! isset($after[$table][$id])) {
                    $dropped++;
                    $selfEdge = $this->isSelfEdge($entries, $row, $world);
                    $this->assertTrue($referenced && ($reason !== null || $selfEdge), "{$ctx}: {$table} row {$id} was deleted but is neither a duplicate of a row the survivor has nor an edge between the merged organisations");
                    $this->bump($selfEdge ? 'self_edge_deleted' : 'duplicate_row_deleted');

                    continue;
                }
                foreach ($moves as $key) {
                    $movedBy[$key] = ($movedBy[$key] ?? 0) + 1;
                }
                $this->assertSame($expected, $after[$table][$id], "{$ctx}: {$table} row {$id} " . ($referenced ? 'should follow the survivor and change nothing else'
                    : ($scoped ? 'does not reference the removed organisation and must not change' : 'belongs to another tenant (or to a campaign of one) and must not change')));
                if ($referenced) {
                    $this->bump('row_moved');
                    $this->bump("moved:{$table}");
                }
            }

            // 1. Nothing in the tenant references the removed organisation any more.
            foreach ($after[$table] as $id => $row) {
                if (! $this->inScope($table, $row, $world)) {
                    continue;
                }
                foreach ($direct as $d) {
                    $this->assertFalse($row[$d['column']] !== null && (int) $row[$d['column']] === $from, "{$ctx}: {$table}.{$d['column']} of row {$id} still references the removed organisation");
                }
                foreach ($entries as $e) {
                    $this->assertFalse($this->referencesOrganisation($e, $row, $from), "{$ctx}: {$table}.{$e['id']} (type {$row[$e['type']]}) of row {$id} still references the removed organisation");
                }
            }

            // 2. Row counts: only the reported duplicates went.
            $this->assertSame($dropped, $this->sum($result['dropped'], $table), "{$ctx}: {$table} lost {$dropped} row(s) but reported " . $this->sum($result['dropped'], $table));
            $this->assertSame(count($before[$table]) - $dropped, count($after[$table]), "{$ctx}: {$table} row count");

            // 3. The reported moved counts are the rows that really moved.
            $reported = array_filter($result['moved'], fn ($key) => str_starts_with($key, "{$table}."), ARRAY_FILTER_USE_KEY);
            ksort($reported);
            ksort($movedBy);
            $this->assertSame($movedBy, $reported, "{$ctx}: reported moved counts for {$table}");
        }
    }

    /** @param array<string, int> $counts */
    private function sum(array $counts, string $table): int
    {
        $total = 0;
        foreach ($counts as $key => $n) {
            if (str_starts_with($key, "{$table}.")) {
                $total += $n;
            }
        }

        return $total;
    }

    /** An edge whose two ends are the two merged organisations. */
    private function isSelfEdge(array $entries, array $row, array $world): bool
    {
        foreach ($entries as $e) {
            if (! isset($e['pair'])) {
                continue;
            }
            $ends = [$row[$e['type']] === 'organization' ? (int) $row[$e['id']] : null, $row[$e['pair']['type']] === 'organization' ? (int) $row[$e['pair']['id']] : null];
            if (in_array($ends, [[$world['from'], $world['to']], [$world['to'], $world['from']]], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a row of the removed organisation is a legitimate duplicate: the survivor has a row (same tenant scope) of the same type with the same values in the rest
     * of the unique key, or an earlier row of the removed organisation has them. Returns the reason, or null.
     *
     * @param array<int, array<string, ?string>> $rows the table before the merge
     */
    private function deletionReason(array $entry, array $row, array $world, array $rows): ?string
    {
        if ($entry['unique'] === []) {
            return null;
        }
        $print = fn (array $r): string => strtolower(implode("\x1f", array_map(fn ($c) => (string) ($r[$c] ?? ''), $entry['unique'])));
        foreach ($rows as $id => $other) {
            if ($id === (int) $row['id'] || ! $this->inScope($entry['table'], $other, $world) || $other[$entry['type']] !== $row[$entry['type']] || $print($other) !== $print($row)) {
                continue;
            }
            if ((int) $other[$entry['id']] === $world['to'] || ((int) $other[$entry['id']] === $world['from'] && $id < (int) $row['id'])) {
                return 'duplicate';
            }
        }

        return null;
    }

    /**
     * The Organisation 360 identity rows. The unique key (tenant, crm_organization_id) means two identities cannot both point at the survivor: the live older one is kept,
     * takes the other's GTM / Signals pointers where it has none, and the other is unlinked and soft-deleted once it points nowhere. No pointer anywhere is lost.
     */
    private function checkIdentity(string $ctx, array $world, array $before, array $after): void
    {
        $tenant = $world['tenant'];
        $loser = null;
        $winner = null;
        foreach ($before as $row) {
            if ((int) $row['sub_institute_id'] !== $tenant || $row['crm_organization_id'] === null) {
                continue;
            }
            if ((int) $row['crm_organization_id'] === $world['from']) {
                $loser = $row;
            } elseif ((int) $row['crm_organization_id'] === $world['to']) {
                $winner = $row;
            }
        }
        $touched = array_map('intval', array_filter([$loser['id'] ?? null, $winner['id'] ?? null]));

        $this->assertSame(count($before), count($after), "{$ctx}: identity rows are soft-deleted, never deleted");
        foreach ($before as $id => $row) {
            if (! in_array($id, $touched, true)) {
                $this->assertSame($row, $after[$id], "{$ctx}: identity row {$id} is not part of this merge and must not change");
            }
        }
        foreach ($after as $row) {
            $this->assertFalse((int) $row['sub_institute_id'] === $tenant && $row['crm_organization_id'] !== null && (int) $row['crm_organization_id'] === $world['from'], "{$ctx}: an identity still points at the removed organisation");
        }
        foreach (['gtm_account_id', 'g2g_company_id'] as $pointer) {
            $collect = function (array $rows) use ($pointer): array {
                $set = array_values(array_unique(array_filter(array_map(fn ($r) => $r[$pointer] === null ? null : $r['sub_institute_id'] . ':' . $r[$pointer], $rows))));
                sort($set);

                return $set;
            };
            $this->assertSame($collect($before), $collect($after), "{$ctx}: a {$pointer} pointer was lost or invented");
        }

        if ($loser === null) {
            return;
        }
        if ($winner === null) {
            $this->assertSame((string) $world['to'], $after[(int) $loser['id']]['crm_organization_id'], "{$ctx}: the only identity of the removed organisation should now point at the survivor");
            $this->bump('identity_loser_only');

            return;
        }

        $this->bump('identity_both');
        $pair = [$loser, $winner];
        usort($pair, fn ($x, $y) => [$x['deleted_at'] !== null, (int) $x['id']] <=> [$y['deleted_at'] !== null, (int) $y['id']]);
        [$keeper, $other] = $pair;
        if ($loser['deleted_at'] !== null || $winner['deleted_at'] !== null) {
            $this->bump('soft_deleted_identity_involved');
        }

        $this->assertSame((string) $world['to'], $after[(int) $keeper['id']]['crm_organization_id'], "{$ctx}: the kept identity (the live older one, {$keeper['id']}) should point at the survivor");
        $this->assertNull($after[(int) $other['id']]['crm_organization_id'], "{$ctx}: the superseded identity {$other['id']} should no longer point at any CRM organisation");
        foreach (['gtm_account_id', 'g2g_company_id'] as $pointer) {
            $this->assertSame($keeper[$pointer] ?? $other[$pointer], $after[(int) $keeper['id']][$pointer], "{$ctx}: the kept identity's {$pointer}");
            if ($keeper[$pointer] === null && $other[$pointer] !== null) {
                $this->bump('identity_pointer_carried');
            }
        }
        $left = $after[(int) $other['id']];
        if ($left['gtm_account_id'] !== null || $left['g2g_company_id'] !== null) {
            // It still carries a pointer the kept identity could not take: it must not be soft-deleted by the merge, or that link would be lost.
            $this->assertSame($other['deleted_at'], $left['deleted_at'], "{$ctx}: an identity that still carries a pointer must keep its deleted state");
            $this->bump($other['deleted_at'] === null ? 'identity_other_kept_alive' : 'identity_other_kept_deleted');
        } else {
            $this->assertNotNull($left['deleted_at'], "{$ctx}: an identity with nothing left to point at should be soft-deleted");
        }
    }
}
