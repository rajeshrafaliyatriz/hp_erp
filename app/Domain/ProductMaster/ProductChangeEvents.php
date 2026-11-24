<?php

namespace App\Domain\ProductMaster;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The history lines of a move or a delete of Products and Sub-products (S15 follow-up, 24 Nov 2026, decision D-8): `product.moved` for each Sub-product that changed Product,
 * `product.removed` for each record that was deleted, and `crm.product.deleted` for the Sell row that went to the Recycle Bin (the line the CRM's own delete writes).
 *
 * Every line goes through the EventRecorder, the one door to the event store; nothing here writes g2g_audit_log. The lines are collected while the change runs and handed to the
 * recorder AFTER the commit (the way ProductWriteAudit does it), so a line is never written for a change that was rolled back, and a failure of the recorder is logged and
 * swallowed: the event is a claim about what the rows now look like, made once that is true, never a part of making it true. A removed record has no screen any more, but its
 * line stays in the event store, filed under the entity id it had.
 */
final class ProductChangeEvents
{
    /** @var list<array{type: string, entityType: string, entityId: int, payload: array<string, mixed>}> */
    private array $lines = [];

    public function __construct(private readonly int $tenant, private readonly ?int $actor)
    {
    }

    /** A Sub-product or an item that now sits under another Product. */
    public function moved(ProductMaster $row, ?string $fromName, int $fromId, string $toName, int $toId): void
    {
        $this->add('product.moved', 'g2g_product_master', (int) $row->id, [
            'name' => (string) $row->name, 'kind' => (string) $row->kind,
            'from' => $fromName, 'to' => $toName, 'fromId' => $fromId > 0 ? $fromId : null, 'toId' => $toId,
            // The shape the History tab already reads, so the line shows as a change of Product.
            'changes' => [['field' => 'product', 'oldValue' => $fromName, 'newValue' => $toName]],
        ]);
    }

    /** @param array<string, mixed> $facts what the record was and what became of its Sub-products */
    public function removed(ProductMaster $row, array $facts): void
    {
        $this->add('product.removed', 'g2g_product_master', (int) $row->id, ['name' => (string) $row->name, 'kind' => (string) $row->kind, 'key' => (string) $row->product_key] + $facts);
    }

    /** The Sell row went to the Recycle Bin: the CRM's own `crm.product.deleted` line, in the shape the CRM history reads. */
    public function sellBinned(int $crmProductId, string $deletedAt, ?int $deletedBy): void
    {
        $changes = [['field' => 'deleted_at', 'oldValue' => null, 'newValue' => $deletedAt]];
        if ($deletedBy !== null) {
            $changes[] = ['field' => 'deleted_by', 'oldValue' => null, 'newValue' => $deletedBy];
        }
        $this->add('crm.product.deleted', 'crm_products', $crmProductId, ['changes' => $changes]);
    }

    /** @return list<array{type: string, entityType: string, entityId: int, payload: array<string, mixed>}> the lines collected so far (for a test) */
    public function lines(): array
    {
        return $this->lines;
    }

    /** Hands every line to the event store once the surrounding transaction has committed (at once when there is none). Never throws. */
    public function flushAfterCommit(): void
    {
        DB::afterCommit(fn () => $this->flush());
    }

    public function flush(): void
    {
        $lines = $this->lines;
        $this->lines = [];
        foreach ($lines as $line) {
            try {
                app(EventRecorder::class)->record($line['type'], $this->tenant, $line['entityType'], $line['entityId'], $this->actor, $line['payload']);
            } catch (\Throwable $e) {
                try {
                    Log::warning('A product change was saved but its history line could not be written (' . $line['type'] . ' #' . $line['entityId'] . ').', ['error' => $e->getMessage()]);
                } catch (\Throwable) {
                    // Even logging must not reach the caller: the change is done.
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function add(string $type, string $entityType, int $entityId, array $payload): void
    {
        $this->lines[] = ['type' => $type, 'entityType' => $entityType, 'entityId' => $entityId, 'payload' => $payload];
    }
}
