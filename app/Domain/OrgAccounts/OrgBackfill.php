<?php

namespace App\Domain\OrgAccounts;

use Illuminate\Support\Facades\DB;

/**
 * Fills g2g_org_accounts from the three places an organisation lives (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md, DB-RULES.md rule 8).
 * One organisation per run, one transaction per organisation, and a dry run (the default) that reads and counts but writes nothing.
 *
 * What it decides is OrgResolver's: links the system already holds, then exact name or domain that is one-to-one and not contradicted by the place.
 * Every live source row ends up in exactly ONE identity: linked rows share one, every other row gets an identity of its own.
 *
 * What it writes: new identities, and a missing pointer on an identity that has none for that source (never replacing one that is there: the update
 * itself only matches a NULL pointer). It never changes a name or a lifecycle that exists, and it NEVER writes to crm_organizations, gtm_accounts or
 * g2g_companies. A second run finds everything in an identity and changes nothing.
 *
 * It works on the application's DEFAULT connection; the command switches that from --database before anything is read.
 */
final class OrgBackfill
{
    public function __construct(private readonly OrgResolver $resolver = new OrgResolver) {}

    /** @return list<int> every organisation with at least one live source row */
    public function tenants(): array
    {
        return OrgSources::tenants();
    }

    /**
     * @return array<string, mixed> the figures the command prints; `tenant` and `apply` first
     *
     * @throws OrgBackfillBlocked when --apply is asked for and a table the run needs is missing (nothing is written)
     */
    public function run(int $tenantId, bool $apply): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('A backfill runs for one organisation: give a positive sub_institute_id.');
        }

        $warnings = [];
        if (! OrgSources::hasIdentityTables()) {
            $message = 'g2g_org_accounts and g2g_org_link_rejections do not exist on this connection: run its migration first (2026_12_09_090000_create_g2g_org_accounts_tables).';
            if ($apply) {
                throw new OrgBackfillBlocked($message);
            }
            $warnings[] = $message . ' This dry run counts as if no identity existed yet.';
        }
        $missing = OrgSources::missingTables();
        if ($missing !== []) {
            $message = implode(', ', $missing) . ' does not exist on this connection.';
            if ($apply) {
                throw new OrgBackfillBlocked($message);
            }
            $warnings[] = $message . ' It is counted as empty.';
        }

        $db = DB::connection();
        if ($apply) {
            $db->beginTransaction();
        }
        try {
            $report = $this->process($tenantId, $apply);
            if ($apply) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $db->rollBack();
            }
            throw $e;
        }

        return ['tenant' => $tenantId, 'apply' => $apply, 'warnings' => $warnings] + $report;
    }

    /** @return array<string, mixed> */
    private function process(int $tenantId, bool $apply): array
    {
        $rows = OrgSources::rows($tenantId);
        $identities = OrgSources::identities($tenantId);
        $plan = $this->resolver->planFrom($rows, $identities, OrgSources::rejections($tenantId));

        $written = 0;
        if ($apply) {
            $written = $this->write($tenantId, $plan, $rows, $identities);
        }

        $perSource = array_fill_keys(OrgAccount::SOURCES, 0);
        foreach ($rows as $row) {
            $perSource[$row['type']]++;
        }
        $c = $plan->counts;

        return [
            'rows' => $perSource,
            'identities' => $c['identities_to_create'],
            'extended' => $c['identities_to_extend'],
            'linked' => [
                OrgAccount::BASIS_EXISTING => $c[OrgAccount::BASIS_EXISTING],
                OrgAccount::BASIS_EXACT_NAME => $c[OrgAccount::BASIS_EXACT_NAME],
                OrgAccount::BASIS_EXACT_DOMAIN => $c[OrgAccount::BASIS_EXACT_DOMAIN],
            ],
            'ambiguous' => $c['ambiguous'],
            'place_conflict' => $c['place_conflict'],
            'same_source_conflict' => $c['same_source_conflict'],
            'identity_conflict' => $c['identity_conflict'],
            'left_alone' => $c['left_alone'],
            // What is still open once this run is done: after the writes the linked rows share an identity; in a dry run the plan says the same.
            'suggestions' => $this->resolver->suggestionCount($tenantId, $apply ? null : $plan),
            'written' => $written,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $identities
     * @return int identities created plus pointers added
     */
    private function write(int $tenantId, OrgPlan $plan, array $rows, array $identities): int
    {
        $byId = [];
        foreach ($identities as $identity) {
            $byId[(int) $identity['id']] = $identity;
        }

        $written = 0;
        foreach ($plan->groups as $group) {
            $written += $this->writeGroup($tenantId, $group, $rows, $byId);
        }

        return $written;
    }

    /**
     * Writes one planned group: a new identity for it, or the missing pointers on the identity it already belongs to. The one place that writes, so the
     * backfill and OrgEnsure (one CRM organisation made after the backfill) cannot disagree.
     *
     * @param  array{identity: ?int, keys: list<string>, attach: array<string, ?string>}  $group
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $byId  the organisation's identities by id; a domain filled in here is remembered
     * @return int identities created plus pointers added
     */
    public function writeGroup(int $tenantId, array $group, array $rows, array &$byId): int
    {
        if ($group['attach'] === []) {
            return 0;
        }

        if ($group['identity'] === null) {
            $attributes = OrgIdentity::fields(array_map(fn (string $key) => $rows[$key], $group['keys'])) + ['sub_institute_id' => $tenantId];
            foreach ($group['attach'] as $key => $basis) {
                $attributes[OrgAccount::POINTERS[$rows[$key]['type']]] = $rows[$key]['id'];
                $attributes[OrgAccount::BASES[$rows[$key]['type']]] = $basis;
            }
            OrgAccount::query()->create($attributes);

            return 1;
        }

        $written = 0;
        foreach ($group['attach'] as $key => $basis) {
            $type = $rows[$key]['type'];
            $pointer = OrgAccount::POINTERS[$type];
            // The update only matches while the pointer is empty, so a pointer that is there is never replaced.
            $changed = OrgAccount::query()->where('id', $group['identity'])->where('sub_institute_id', $tenantId)->whereNull($pointer)
                ->update([$pointer => $rows[$key]['id'], OrgAccount::BASES[$type] => $basis]);
            if ($changed !== 1) {
                throw new \RuntimeException("Identity {$group['identity']} changed while the backfill ran: its {$pointer} was no longer empty.");
            }
            $written++;
            if ($rows[$key]['domain'] !== null && ($byId[$group['identity']]['domain'] ?? null) === null) {
                OrgAccount::query()->where('id', $group['identity'])->whereNull('domain')->update(['domain' => mb_substr($rows[$key]['domain'], 0, 191)]);
                $byId[$group['identity']]['domain'] = $rows[$key]['domain'];
            }
        }

        return $written;
    }
}
