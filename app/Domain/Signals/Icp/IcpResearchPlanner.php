<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Support\Carbon;

/**
 * Decides what one research run searches for, for one ICP: a rotating slice of its query catalogue plus one search
 * per named account that has not been checked recently, best prospects first. Used by the real run and by the
 * preview endpoint, so what an administrator previews is what will run. Reads the database; calls nothing external.
 */
final class IcpResearchPlanner
{
    /**
     * @return array{specs: list<array<string, mixed>>, account_ids: list<int>, accounts: list<array<string, mixed>>}
     */
    public function plan(int $tenantId, IcpPack $pack, ?Carbon $now = null): array
    {
        $cfg = $pack->config();
        $max = max(1, (int) config('signals.opportunities.max_queries', 6));
        $now ??= now(config('signals.timezone'));
        // `signals:research --batches=N` moves each batch on by a day so it takes the next slice of the query catalogue.
        $now = $now->copy()->addDays((int) config('signals.icp.batch_shift', 0));

        $specs = array_map(fn ($q) => array_filter([
            'q' => $q['q'], 'topic' => $q['topic'], 'days' => $q['days'], 'domains' => $q['domains'] ?? null, 'source' => $q['source'], 'id' => $q['id'],
        ]), $pack->catalogueForRun($now));

        $accounts = TargetAccount::where('sub_institute_id', $tenantId)->where('pack', $pack->key)->where('watch_priority', '<', 9)
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subDays((int) ($cfg['account_recheck_days'] ?? 7))))
            ->orderBy('watch_priority')->orderByRaw('last_checked_at IS NOT NULL')->orderBy('last_checked_at')->orderBy('id')
            ->limit((int) ($cfg['per_run']['accounts'] ?? 3))->get();
        foreach ($accounts as $a) {
            $specs[] = $pack->accountQuery($a->name, $a->city) + ['source' => 'account', 'id' => 'account-' . $a->id];
        }

        return [
            'specs' => array_slice($specs, 0, $max),
            'account_ids' => $accounts->pluck('id')->map(fn ($i) => (int) $i)->all(),
            'accounts' => $accounts->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'city' => $a->city, 'priority' => (int) $a->watch_priority])->all(),
        ];
    }
}
