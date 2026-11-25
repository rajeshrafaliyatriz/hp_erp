<?php

namespace Tests\Feature;

use App\Domain\Crm\Contacts\ContactMerger;
use App\Domain\Crm\Contacts\ContactReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property test for the contact merge (S18, the twin of OrganisationMergePropertyTest): seeded random worlds built FROM THE REGISTRY, one minimal table per registry entry, several
 * organisations, several contacts per organisation, random referencing rows (some already on the survivor, some of other organisations carrying the very same contact ids, some with a type that
 * is not a contact), a random pair merged in one organisation, and then the invariants checked row by row:
 *
 *   1. nothing in the organisation references the removed contact in ANY registry entry;
 *   2. the only rows that disappear are duplicates the survivor already had (or a line between the two merged contacts), and their number equals the reported count;
 *   3. every other row that referenced the removed contact references the survivor now and is otherwise identical;
 *   4. no row of another organisation, and no row that never referenced the contact, changed at all;
 *   5. merging the same pair again changes nothing and reports zeros.
 *
 * The expected state is computed here from the rows, not by calling the merger. Every iteration has its own seed (printed in any failure): mt_srand(BASE_SEED + iteration). Coverage counters at the
 * end fail the test if the generator stopped producing the situations it exists to test. In-memory sqlite only.
 */
class ContactMergePropertyTest extends TestCase
{
    private const ITERATIONS = 150;

    private const BASE_SEED = 20261125;

    private const TENANTS = [1, 2, 3];

    /** The registry's `unique` for these is a deduplication rule, not a database key: the real table has no unique index. */
    private const NO_DB_KEY = ['crm_entity_relationships'];

    /** @var array<string, array{tenant: ?string, via: ?array<string, string>, viaColumn: ?string, cols: array<string, string>, keys: list<list<string>>}> */
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
                if ($s['viaColumn'] !== null) {
                    $b->unsignedBigInteger($s['viaColumn'])->nullable();
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

    /** @return array<string, array{tenant: ?string, via: ?array<string, string>, viaColumn: ?string, cols: array<string, string>, keys: list<list<string>>}> */
    private function specFromRegistry(): array
    {
        $spec = [];
        $table = function (string $name) use (&$spec): void {
            $spec[$name] ??= ['tenant' => null, 'via' => null, 'viaColumn' => null, 'cols' => [], 'keys' => []];
        };
        $kindOf = fn (string $column): string => str_ends_with($column, '_id') ? 'int' : 'string';

        foreach (ContactReferences::direct() as $d) {
            $table($d['table']);
            $spec[$d['table']]['tenant'] = $d['scope'];
            $spec[$d['table']]['cols'][$d['column']] = 'int';
        }
        foreach (ContactReferences::junctions() as $j) {
            $table($j['table']);
            $spec[$j['table']]['cols'][$j['column']] = 'int';
            $spec[$j['table']]['via'] = $j['scope'];
            $spec[$j['table']]['viaColumn'] = $j['scope']['via'];
            $spec[$j['table']]['keys'][] = [$j['parent'], $j['column']];
            $table($j['scope']['parent']);
            $spec[$j['scope']['parent']]['tenant'] = $j['scope']['parent_tenant'];
        }
        foreach (ContactReferences::polymorphic() as $p) {
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
                $spec[$p['table']]['viaColumn'] = $p['scope']['via'];
                $table($p['scope']['parent']);
                $spec[$p['scope']['parent']]['tenant'] = $p['scope']['parent_tenant'];
                unset($spec[$p['table']]['cols'][$p['scope']['via']]);
            }
            if ($p['unique'] !== [] && ! in_array($p['table'], self::NO_DB_KEY, true)) {
                $spec[$p['table']]['keys'][] = array_merge([$p['type'], $p['id']], $p['unique']);
            }
        }

        return $spec;
    }

    // ── the property ─────────────────────────────────────────────────────────

    public function test_the_registry_still_lists_every_reference_point_known_when_it_was_written_with_the_value_each_table_stores(): void
    {
        $direct = array_map(fn ($c) => "{$c['table']}.{$c['column']}", array_merge(ContactReferences::direct(), ContactReferences::junctions()));
        $this->assertSame([], array_values(array_diff(['crm_quotes.contact_id', 'crm_sales_orders.contact_id', 'crm_purchase_orders.contact_id', 'crm_invoices.contact_id', 'crm_service_contracts.contact_id',
            'crm_tickets.contact_id', 'crm_leads.converted_contact_id', 'crm_contact_comments.contact_id', 'crm_contacts.reports_to_id', 'crm_opportunity_contacts.contact_id', 'crm_vendor_contacts.contact_id'], $direct)));

        $polymorphic = [];
        foreach (ContactReferences::polymorphic() as $p) {
            foreach ($p['values'] as $value) {
                $polymorphic[] = "{$p['table']}.{$p['id']} by {$p['type']} = {$value}";
            }
        }
        $this->assertSame([], array_values(array_diff([
            'crm_campaign_targets.target_id by target_type = contact', 'crm_email_links.linked_id by linked_type = CONTACT', 'document_library_links.linked_id by linked_type = CONTACT',
            'task_management_calendar_events.linked_id by linked_type = CONTACT', 'crm_sms_log.related_id by related_type = contact', 'crm_outreach_approvals.target_id by target_type = contact',
            'crm_knowledge_sources.entity_id by entity_type = contact', 'crm_consent_preferences.entity_id by entity_type = contact', 'g2g_entity_facts.entity_id by entity_type = contact',
            'crm_entity_relationships.from_id by from_type = contact', 'crm_entity_relationships.to_id by to_type = contact', 'g2g_custom_field_values.record_id by record_table = crm_contacts',
        ], $polymorphic)), 'polymorphic references (or the exact value a table stores for a contact: upper or lower case) missing from ContactReferences::polymorphic()');
    }

    public function test_after_a_merge_nothing_references_the_removed_contact_and_nothing_else_moves(): void
    {
        $this->coverage = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $seed = self::BASE_SEED + $i;
            foreach (array_keys($this->spec) as $table) {
                DB::table($table)->delete();
            }
            $world = $this->buildWorld($seed);
            $ctx = "seed {$seed}: merging contact {$world['from']} into {$world['to']} in tenant {$world['tenant']}";

            $before = $this->snapshot();
            $result = DB::transaction(fn () => ContactMerger::repoint($world['tenant'], $world['from'], $world['to']));
            $after = $this->snapshot();

            $this->checkInvariants($ctx, $world, $before, $after, $result);

            $again = DB::transaction(fn () => ContactMerger::repoint($world['tenant'], $world['from'], $world['to']));
            $this->assertSame(['moved' => [], 'dropped' => [], 'cleared' => []], $again, "{$ctx}: merging the same pair again must report nothing");
            $this->assertSame($after, $this->snapshot(), "{$ctx}: merging the same pair again must change nothing");
        }

        foreach (['duplicate_row_deleted', 'self_edge_deleted', 'row_moved', 'other_tenant_row_with_same_contact', 'wrong_type_row_with_same_id', 'survivor_reported_to_removed', 'report_moved', 'junction_duplicate_deleted'] as $situation) {
            $this->assertGreaterThan(0, $this->coverage[$situation] ?? 0, "the generator never produced: {$situation} (in " . self::ITERATIONS . ' worlds)');
        }
        foreach (array_keys($this->spec) as $table) {
            if (! in_array($table, ['crm_campaigns', 'crm_opportunities', 'crm_vendors'], true)) {
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

    /** @return array{tenant: int, from: int, to: int, contacts: array<int, list<int>>, parents: array<string, array<int, int>>} */
    private function buildWorld(int $seed): array
    {
        mt_srand($seed);
        $contacts = [];
        foreach (self::TENANTS as $t) {
            $copy = range(1, 9);
            $mine = [];
            for ($k = mt_rand(3, 5); $k > 0; $k--) {
                $mine[] = array_splice($copy, mt_rand(0, count($copy) - 1), 1)[0];
            }
            $contacts[$t] = $mine;
        }
        $tenant = $this->pick(self::TENANTS);
        $rest = $contacts[$tenant];
        $from = array_splice($rest, mt_rand(0, count($rest) - 1), 1)[0];
        $world = ['tenant' => $tenant, 'from' => $from, 'to' => $this->pick($rest), 'contacts' => $contacts, 'parents' => []];

        // The parents a junction or a campaign target is scoped through: two per tenant.
        foreach ($this->parentTables() as $parent) {
            foreach (self::TENANTS as $t) {
                foreach ([1, 2] as $k) {
                    $world['parents'][$parent][$t * 10 + $k] = $t;
                    DB::table($parent)->insert(['id' => $t * 10 + $k, 'sub_institute_id' => $t]);
                }
            }
        }

        foreach (array_keys($this->spec) as $table) {
            match (true) {
                in_array($table, $this->parentTables(), true) => null,
                $table === 'crm_contacts' => $this->contactRows($world),
                $table === 'crm_entity_relationships' => $this->edgeRows($world),
                default => $this->plainRows($table, $world),
            };
        }

        return $world;
    }

    /** @return list<string> */
    private function parentTables(): array
    {
        $parents = [];
        foreach ($this->spec as $s) {
            if ($s['via'] !== null) {
                $parents[$s['via']['parent']] = true;
            }
        }

        return array_keys($parents);
    }

    /** A contact id for a row of this tenant: mostly the tenant's own, often the pair being merged (also for OTHER tenants: ids are plain numbers, so coincidences happen), sometimes nothing. */
    private function ref(array $world, int $tenant, bool $nullable = true): ?int
    {
        $r = mt_rand(1, 100);

        return match (true) {
            $r <= 45 => $this->pick($world['contacts'][$tenant]),
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
                $parentIds = array_keys($world['parents'][$s['via']['parent']]);
                $row[$s['viaColumn']] = $this->pick($parentIds);
                $tenant = $world['parents'][$s['via']['parent']][$row[$s['viaColumn']]];
            }
            $refs = [];
            if ($entry === null) {
                foreach (array_keys($s['cols']) as $column) {
                    $refs[] = $row[$column] = $this->ref($world, $tenant);
                }
            } else {
                $row[$entry['type']] = $this->chance(0.72) ? $this->pick($entry['values']) : $this->otherType($entry['values'][0]);
                $refs[] = $row[$entry['id']] = $this->ref($world, $tenant, false);
                foreach ($entry['unique'] as $column) {
                    if ($column !== ($s['viaColumn'] ?? null)) {
                        // Tenant-disjoint, as in real data (e-mail, document and field ids belong to one tenant): a coincidence of contact ids across tenants never collides.
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
                $this->bump('other_tenant_row_with_same_contact');
            }
            if ($entry !== null && ! in_array($row[$entry['type']], $entry['values'], true) && $tenant === $world['tenant'] && $row[$entry['id']] === $world['from']) {
                $this->bump('wrong_type_row_with_same_id');
            }
        }
    }

    /** A type word that is not a contact, in the same case convention as the table. */
    private function otherType(string $contact): string
    {
        return match (true) {
            $contact === 'crm_contacts' => $this->pick(['tbluser', 'crm_organizations']),
            $contact === strtoupper($contact) => $this->pick(['LEAD', 'ORGANIZATION', 'OPPORTUNITY']),
            default => $this->pick(['lead', 'organization', 'opportunity']),
        };
    }

    private function contactRows(array $world): void
    {
        // The pair itself (real rows with their own primary key; the survivor sometimes reports to the removed contact, and the other way round), then other people, possibly of other tenants.
        DB::table('crm_contacts')->insert(['id' => $world['from'], 'sub_institute_id' => $world['tenant'], 'reports_to_id' => $this->chance(0.2) ? $world['to'] : null]);
        DB::table('crm_contacts')->insert(['id' => $world['to'], 'sub_institute_id' => $world['tenant'], 'reports_to_id' => $this->chance(0.3) ? $world['from'] : $this->pick([null, 99])]);
        for ($n = mt_rand(0, 6), $id = 100; $n > 0; $n--) {
            $t = $this->chance(0.55) ? $world['tenant'] : $this->pick($this->otherTenants($world['tenant']));
            DB::table('crm_contacts')->insert(['id' => ++$id, 'sub_institute_id' => $t, 'reports_to_id' => $this->ref($world, $t)]);
        }
    }

    private function edgeRows(array $world): void
    {
        $seen = [];
        for ($n = mt_rand(0, 8), $id = 0; $n > 0; $n--) {
            $tenant = $this->chance(0.55) ? $world['tenant'] : $this->pick($this->otherTenants($world['tenant']));
            $row = ['id' => ++$id, 'sub_institute_id' => $tenant, 'relationship_type' => $this->pick(['colleague_of', 'influenced_by'])];
            foreach (['from', 'to'] as $end) {
                $isContact = $this->chance(0.7);
                $row["{$end}_type"] = $isContact ? 'contact' : $this->pick(['organization', 'lead']);
                $row["{$end}_id"] = $isContact ? $this->ref($world, $tenant, false) : mt_rand(1, 9);
            }
            // A contact related to itself is not a world the merge is asked to repair.
            if ($row['from_type'] === 'contact' && $row['to_type'] === 'contact' && $row['from_id'] === $row['to_id']) {
                continue;
            }
            $fingerprint = implode('|', [$tenant, $row['from_type'], $row['from_id'], $row['to_type'], $row['to_id'], $row['relationship_type']]);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            DB::table('crm_entity_relationships')->insert($row);
        }
        // A line between the two merged contacts, which would relate the survivor to itself once moved.
        if ($this->chance(0.4)) {
            [$a, $b] = $this->chance(0.5) ? [$world['from'], $world['to']] : [$world['to'], $world['from']];
            DB::table('crm_entity_relationships')->insert(['id' => 90, 'sub_institute_id' => $world['tenant'], 'from_type' => 'contact', 'from_id' => $a, 'to_type' => 'contact', 'to_id' => $b, 'relationship_type' => 'colleague_of']);
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
        if ($s['via'] !== null) {
            return ($world['parents'][$s['via']['parent']][(int) $row[$s['viaColumn']]] ?? null) === $world['tenant'];
        }

        return (int) $row[$s['tenant']] === $world['tenant'];
    }

    /** @return list<array<string, mixed>> the polymorphic registry entries of a table */
    private function entries(string $table): array
    {
        return array_values(array_filter(ContactReferences::polymorphic(), fn ($p) => $p['table'] === $table));
    }

    private function referencesContact(array $entry, array $row, int $contact): bool
    {
        return in_array($row[$entry['type']], $entry['values'], true) && $row[$entry['id']] !== null && (int) $row[$entry['id']] === $contact;
    }

    private function checkInvariants(string $ctx, array $world, array $before, array $after, array $result): void
    {
        $from = $world['from'];
        $to = $world['to'];

        foreach (array_keys($this->spec) as $table) {
            if (in_array($table, $this->parentTables(), true)) {
                $this->assertSame($before[$table], $after[$table], "{$ctx}: {$table} (a parent row) must not change");

                continue;
            }
            $direct = array_values(array_filter(array_merge(ContactReferences::direct(), array_map(fn ($j) => $j + ['scope' => 'junction'], ContactReferences::junctions())), fn ($d) => $d['table'] === $table));
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
                            $this->bump('survivor_reported_to_removed');
                        } else {
                            $expected[$d['column']] = (string) $to;
                            $moves[] = "{$table}.{$d['column']}";
                            if ($d['self_reference'] ?? false) {
                                $this->bump('report_moved');
                            }
                            $reason ??= isset($d['parent']) ? $this->junctionDuplicate($d, $row, $world, $before[$table]) : null;
                        }
                    }
                }
                foreach ($entries as $e) {
                    if ($scoped && $this->referencesContact($e, $row, $from)) {
                        $expected[$e['id']] = (string) $to;
                        $moves[] = "{$table}.{$e['id']}";
                        $reason ??= $this->deletionReason($e, $row, $world, $before[$table]);
                    }
                }
                $referenced = $expected !== $row;

                if (! isset($after[$table][$id])) {
                    $dropped++;
                    $selfEdge = $this->isSelfEdge($entries, $row, $world);
                    $this->assertTrue($referenced && ($reason !== null || $selfEdge), "{$ctx}: {$table} row {$id} was deleted but is neither a duplicate of a row the survivor has nor a line between the merged contacts");
                    $isJunction = array_filter($direct, fn ($d) => isset($d['parent'])) !== [];
                    $this->bump($selfEdge ? 'self_edge_deleted' : ($isJunction ? 'junction_duplicate_deleted' : 'duplicate_row_deleted'));

                    continue;
                }
                foreach ($moves as $key) {
                    $movedBy[$key] = ($movedBy[$key] ?? 0) + 1;
                }
                $this->assertSame($expected, $after[$table][$id], "{$ctx}: {$table} row {$id} " . ($referenced ? 'should follow the survivor and change nothing else'
                    : ($scoped ? 'does not reference the removed contact and must not change' : 'belongs to another organisation (or to a parent of one) and must not change')));
                if ($referenced) {
                    $this->bump('row_moved');
                    $this->bump("moved:{$table}");
                }
            }

            // 1. Nothing in the organisation references the removed contact any more.
            foreach ($after[$table] as $id => $row) {
                if (! $this->inScope($table, $row, $world)) {
                    continue;
                }
                foreach ($direct as $d) {
                    $this->assertFalse($row[$d['column']] !== null && (int) $row[$d['column']] === $from, "{$ctx}: {$table}.{$d['column']} of row {$id} still references the removed contact");
                }
                foreach ($entries as $e) {
                    $this->assertFalse($this->referencesContact($e, $row, $from), "{$ctx}: {$table}.{$e['id']} (type {$row[$e['type']]}) of row {$id} still references the removed contact");
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

    /** A line whose two ends are the two merged contacts. */
    private function isSelfEdge(array $entries, array $row, array $world): bool
    {
        foreach ($entries as $e) {
            if (! isset($e['pair'])) {
                continue;
            }
            $ends = [$row[$e['type']] === 'contact' ? (int) $row[$e['id']] : null, $row[$e['pair']['type']] === 'contact' ? (int) $row[$e['pair']['id']] : null];
            if (in_array($ends, [[$world['from'], $world['to']], [$world['to'], $world['from']]], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a row of the removed contact is a legitimate duplicate: the survivor has a row (same scope) of the same type with the same values in the rest of the unique key, or an earlier row of
     * the removed contact has them. Returns the reason, or null.
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

    /** The same, for a junction row: the survivor is already on that parent, or an earlier row of the removed contact is. */
    private function junctionDuplicate(array $junction, array $row, array $world, array $rows): ?string
    {
        foreach ($rows as $id => $other) {
            if ($id === (int) $row['id'] || $other[$junction['parent']] !== $row[$junction['parent']] || ! $this->inScope($junction['table'], $other, $world)) {
                continue;
            }
            if ((int) $other[$junction['column']] === $world['to'] || ((int) $other[$junction['column']] === $world['from'] && $id < (int) $row['id'])) {
                return 'duplicate';
            }
        }

        return null;
    }
}
