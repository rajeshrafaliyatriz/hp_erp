<?php

namespace App\Domain\OrgAccounts;

use Illuminate\Support\Facades\DB;

/**
 * What a person decides about a suggested pair: they ARE the same organisation (confirm) or they are NOT (reject).
 *
 * Both ends must be live source rows of the caller's organisation; anything else is OrgLinkNotFound, the same answer for "does not exist" and
 * "belongs to another organisation". A confirm is one transaction and either makes the link or changes nothing.
 */
final class OrgLinker
{
    /**
     * Puts the two source rows in ONE identity and returns it. Creates the identity when neither row has one, attaches the row to the other's
     * identity, or merges two identities into the older one. Basis `manual`. Refused with OrgLinkConflict when an organisation would end up
     * with two rows of one source (an identity holds one CRM account, one GTM account and one Signals company).
     *
     * @return array<string, mixed> the identity: id, name, normalized_name, domain, lifecycle, the three pointers, their bases, owner_user_id
     *
     * @throws OrgLinkNotFound
     * @throws OrgLinkConflict
     */
    public function confirm(int $tenantId, ?int $userId, string $leftType, int $leftId, string $rightType, int $rightId): array
    {
        [$left, $right] = $this->rows($tenantId, $leftType, $leftId, $rightType, $rightId);
        $this->refuseTwoOfOneSource($left, $right);

        return DB::transaction(function () use ($tenantId, $userId, $left, $right) {
            $leftIdentity = $this->identityOf($tenantId, $left);
            $rightIdentity = $this->identityOf($tenantId, $right);

            if ($leftIdentity !== null && $rightIdentity !== null) {
                $identity = (int) $leftIdentity->id === (int) $rightIdentity->id ? $leftIdentity : $this->merge($leftIdentity, $rightIdentity, $userId);
            } elseif ($leftIdentity !== null || $rightIdentity !== null) {
                $identity = $this->attach($leftIdentity ?? $rightIdentity, $leftIdentity === null ? $left : $right, $userId);
            } else {
                $identity = $this->create($tenantId, [$left, $right], $userId);
            }

            // The latest decision wins: a pair once turned down and now confirmed is no longer rejected.
            DB::table('g2g_org_link_rejections')->where('sub_institute_id', $tenantId)->where(function ($q) use ($left, $right) {
                $q->where(fn ($p) => $this->pair($p, $left, $right))->orWhere(fn ($p) => $this->pair($p, $right, $left));
            })->delete();

            return OrgIdentity::present($identity->fresh()->getAttributes());
        });
    }

    /**
     * Remembers that these two are NOT the same organisation, so they are never offered again (nor linked by the backfill). Saying it twice is
     * the same as saying it once. No identity changes.
     *
     * @throws OrgLinkNotFound
     * @throws OrgLinkConflict
     */
    public function reject(int $tenantId, ?int $userId, string $leftType, int $leftId, string $rightType, int $rightId): void
    {
        [$left, $right] = $this->rows($tenantId, $leftType, $leftId, $rightType, $rightId);
        // Stored in one order, so the unique index sees (a, b) and (b, a) as the same pair.
        [$first, $second] = OrgSources::compare($left['key'], $right['key']) <= 0 ? [$left, $right] : [$right, $left];

        $exists = DB::table('g2g_org_link_rejections')->where('sub_institute_id', $tenantId)->where(function ($q) use ($first, $second) {
            $q->where(fn ($p) => $this->pair($p, $first, $second))->orWhere(fn ($p) => $this->pair($p, $second, $first));
        })->exists();
        if ($exists) {
            return;
        }

        DB::table('g2g_org_link_rejections')->insertOrIgnore([
            'sub_institute_id' => $tenantId, 'left_type' => $first['type'], 'left_id' => $first['id'],
            'right_type' => $second['type'], 'right_id' => $second['id'], 'decided_by' => $userId, 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ steps

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function rows(int $tenantId, string $leftType, int $leftId, string $rightType, int $rightId): array
    {
        foreach ([$leftType, $rightType] as $type) {
            if (! in_array($type, OrgAccount::SOURCES, true)) {
                throw new \InvalidArgumentException("'{$type}' is not a source type.");
            }
        }
        $left = OrgSources::row($tenantId, $leftType, $leftId);
        $right = OrgSources::row($tenantId, $rightType, $rightId);
        if ($left === null || $right === null) {
            throw new OrgLinkNotFound('One of the two records was not found.');
        }
        if ($left['key'] === $right['key']) {
            throw new OrgLinkConflict(OrgLinkConflict::SAME_ROW, 'A record cannot be linked to itself.');
        }

        return [$left, $right];
    }

    /** One organisation holds one row per source, so two rows of one source can be turned down but never confirmed as one organisation. */
    private function refuseTwoOfOneSource(array $left, array $right): void
    {
        if ($left['type'] === $right['type']) {
            throw new OrgLinkConflict(OrgLinkConflict::SAME_SOURCE, 'Both records are of the same kind, and one organisation holds only one record of each kind, so they cannot be shown as one.');
        }
    }

    private function identityOf(int $tenantId, array $row): ?OrgAccount
    {
        return OrgAccount::query()->where('sub_institute_id', $tenantId)->where(OrgAccount::POINTERS[$row['type']], $row['id'])->lockForUpdate()->first();
    }

    /** @param list<array<string, mixed>> $rows */
    private function create(int $tenantId, array $rows, ?int $userId): OrgAccount
    {
        $attributes = OrgIdentity::fields($rows) + ['sub_institute_id' => $tenantId, 'created_by' => $userId, 'updated_by' => $userId];
        foreach ($rows as $row) {
            $attributes[OrgAccount::POINTERS[$row['type']]] = $row['id'];
            $attributes[OrgAccount::BASES[$row['type']]] = OrgAccount::BASIS_MANUAL;
        }

        return OrgAccount::query()->create($attributes);
    }

    /** @param array<string, mixed> $row the source row that has no identity yet */
    private function attach(OrgAccount $identity, array $row, ?int $userId): OrgAccount
    {
        $pointer = OrgAccount::POINTERS[$row['type']];
        if ($identity->{$pointer} !== null) {
            throw new OrgLinkConflict(OrgLinkConflict::SAME_SOURCE, 'That organisation already has a different record from this source.');
        }

        $identity->{$pointer} = $row['id'];
        $identity->{OrgAccount::BASES[$row['type']]} = OrgAccount::BASIS_MANUAL;
        if ($identity->domain === null && $row['domain'] !== null) {
            $identity->domain = mb_substr($row['domain'], 0, 191);
        }
        $identity->lifecycle = OrgIdentity::raise($identity->lifecycle, OrgIdentity::lifecycleFor([$row]));
        $identity->updated_by = $userId;
        $identity->save();

        return $identity;
    }

    /** The older identity (lower id) survives; the other gives up its rows and is deleted. Refused when both hold a row of one source. */
    private function merge(OrgAccount $one, OrgAccount $two, ?int $userId): OrgAccount
    {
        [$survivor, $loser] = (int) $one->id < (int) $two->id ? [$one, $two] : [$two, $one];

        $moves = [];
        foreach (OrgAccount::POINTERS as $type => $pointer) {
            if ($loser->{$pointer} === null) {
                continue;
            }
            if ($survivor->{$pointer} !== null) {
                throw new OrgLinkConflict(OrgLinkConflict::SAME_SOURCE, 'Both organisations already have a record from the same source, so they cannot be one.');
            }
            $moves[$type] = (int) $loser->{$pointer};
        }

        // The loser lets go of its rows first: each pointer is unique per organisation, deleted rows included.
        $loser->forceFill(array_fill_keys(array_merge(array_values(OrgAccount::POINTERS), array_values(OrgAccount::BASES)), null));
        $loser->updated_by = $userId;
        $loser->save();
        $loser->delete();
        // The agent's runs and proposals about the removed identity follow it to the survivor (never throws; a no-op on a host without the agent tables).
        \App\Domain\AgentGateway\AgentSubjects::repoint((int) $survivor->sub_institute_id, (int) $loser->id, (int) $survivor->id);

        foreach ($moves as $type => $id) {
            $survivor->{OrgAccount::POINTERS[$type]} = $id;
            $survivor->{OrgAccount::BASES[$type]} = OrgAccount::BASIS_MANUAL;
        }
        $survivor->domain ??= $loser->domain;
        $survivor->owner_user_id ??= $loser->owner_user_id;
        $survivor->lifecycle = OrgIdentity::raise($survivor->lifecycle, $loser->lifecycle);
        $survivor->updated_by = $userId;
        $survivor->save();

        return $survivor;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function pair($query, array $left, array $right): void
    {
        $query->where('left_type', $left['type'])->where('left_id', $left['id'])->where('right_type', $right['type'])->where('right_id', $right['id']);
    }
}
