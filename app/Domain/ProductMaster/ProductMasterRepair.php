<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;

/**
 * Brings the Product Master back in step with the three stores that own the products (the CRM catalogue, the Product Portfolio and the
 * organisation's own products), for the case where the write-through missed a change: a store written by an import or a script, a failure the
 * write-through logged and swallowed, or a host that has not had the code for a while.
 *
 * Two steps, both safe to repeat:
 *   1. the BACKFILL (ProductMasterBackfill) creates every row a source should have and has none;
 *   2. the SYNC (ProductMasterSync::reconcile) makes every existing row agree with its source: a changed name, a moved offer, a source that is
 *      gone (the row is archived, or becomes a catalogue item when it is still sold in the CRM), a deleted offer that was created again.
 * A row a person archived on purpose stays archived. Nothing is deleted.
 *
 * A dry run (the default) reports what each step would do and writes nothing. A second run reports nothing to do.
 */
final class ProductMasterRepair
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return array{tenant:int, apply:bool, created:array<string,int>, existing:array<string,int>, synced:array<string,int>, issues:list<string>}
     */
    public function run(int $tenantId, bool $apply): array
    {
        $backfill = (new ProductMasterBackfill($this->connection))->run($tenantId, $apply);

        $sync = new ProductMasterSync($this->connection, $apply, null, false);
        $db = DB::connection($this->connection);
        $synced = $apply ? $db->transaction(fn () => $sync->reconcile($tenantId)) : $sync->reconcile($tenantId);
        unset($synced[ProductMasterSync::ACT_UNCHANGED]);
        ksort($synced);

        return [
            'tenant' => $tenantId, 'apply' => $apply, 'created' => $backfill['created'], 'existing' => $backfill['existing'], 'synced' => $synced,
            'issues' => array_values(array_unique(array_merge($backfill['issues'], $sync->issues()))),
        ];
    }
}
