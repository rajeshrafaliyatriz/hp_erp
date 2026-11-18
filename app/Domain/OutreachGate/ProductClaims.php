<?php

namespace App\Domain\OutreachGate;

use App\Services\Events\EventCatalogue;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The write side of the approved claims (table g2g_product_claims): propose a sentence for a product, approve it, retire it. The gate reads them through ClaimBook.
 *
 *   - Every read and write carries the organisation. A claim of another organisation is "not found" (null here, a 404 in the API); a product the organisation cannot
 *     see (not its own, not shared) is a ClaimRejected (a 422).
 *   - A claim is immutable: its text is never edited (the hash is its identity, normalised, so the same sentence cannot be filed twice for a product). To change a
 *     claim, propose the new sentence and retire the old one.
 *   - A claim is audited: a `outreach.claim.proposed` / `outreach.claim.decided` event is written in the same transaction as the change, so a change and its trail are
 *     saved together or not at all.
 *   - Nothing here sends or schedules anything.
 */
final class ProductClaims
{
    public const STATUSES = ['proposed', 'approved', 'retired'];

    /** What may be decided about a claim. */
    public const DECISIONS = ['approved', 'retired'];

    public const MIN_WORDS = 3;

    public function __construct(private readonly ClaimBook $book)
    {
    }

    /**
     * @return array<string, mixed> the stored claim, as the API presents it
     *
     * @throws ClaimRejected
     */
    public function propose(int $tenant, int $userId, int $productId, string $claim, ?string $sourceUrl): array
    {
        $claim = trim((string) preg_replace('/\s+/u', ' ', $claim));
        if (Text::words(Text::normalise($claim)) < self::MIN_WORDS) {
            throw new ClaimRejected('A claim is a sentence of at least ' . self::MIN_WORDS . ' words.');
        }
        if ($this->book->visibleProductIds($tenant, [$productId]) === []) {
            throw new ClaimRejected('That product was not found for this organisation.');
        }

        $hash = ClaimBook::hash($claim);
        $existing = DB::table('g2g_product_claims')->where('sub_institute_id', $tenant)->where('product_master_id', $productId)->where('claim_hash', $hash)->first();
        if ($existing !== null) {
            throw new ClaimRejected("That claim already exists for this product (claim #{$existing->id}, {$existing->status}).");
        }

        $id = DB::transaction(function () use ($tenant, $userId, $productId, $claim, $hash, $sourceUrl) {
            $id = (int) DB::table('g2g_product_claims')->insertGetId([
                'sub_institute_id' => $tenant, 'product_master_id' => $productId, 'claim' => $claim, 'claim_hash' => $hash,
                'source_url' => $sourceUrl === null || trim($sourceUrl) === '' ? null : trim($sourceUrl), 'status' => 'proposed',
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event('outreach.claim.proposed', $tenant, $id, $userId, ['product_master_id' => $productId, 'claim_hash' => $hash], "outreach.claim.proposed:{$tenant}:{$id}");

            return $id;
        });

        return $this->present(DB::table('g2g_product_claims')->where('id', $id)->first());
    }

    /**
     * Approve or retire a claim of this organisation. Returns null when there is no such claim for it. Asking for the status it already has changes nothing and
     * writes no event.
     *
     * @return array<string, mixed>|null
     *
     * @throws ClaimRejected
     */
    public function decide(int $tenant, int $userId, int $claimId, string $status): ?array
    {
        if (! in_array($status, self::DECISIONS, true)) {
            throw new ClaimRejected('A claim can be approved or retired.');
        }
        $row = $this->find($tenant, $claimId);
        if ($row === null) {
            return null;
        }
        if ($row->status === $status) {
            return $this->present($row);
        }

        DB::transaction(function () use ($tenant, $userId, $row, $status) {
            $change = ['status' => $status, 'updated_at' => now()];
            if ($status === 'approved') {
                $change += ['approved_by' => $userId, 'approved_at' => now()];
            }
            DB::table('g2g_product_claims')->where('id', $row->id)->where('sub_institute_id', $tenant)->update($change);
            $this->event('outreach.claim.decided', $tenant, (int) $row->id, $userId, ['product_master_id' => (int) $row->product_master_id, 'claim_hash' => $row->claim_hash, 'from' => $row->status, 'to' => $status], 'outreach.claim.decided:' . $tenant . ':' . $row->id . ':' . Str::uuid());
        });

        return $this->present($this->find($tenant, $claimId));
    }

    public function find(int $tenant, int $claimId): ?object
    {
        return $tenant <= 0 || $claimId <= 0 ? null : (DB::table('g2g_product_claims')->where('id', $claimId)->where('sub_institute_id', $tenant)->first() ?: null);
    }

    /**
     * @return array{claims: list<array<string, mixed>>, total: int}
     */
    public function list(int $tenant, ?int $productId, ?string $status, int $limit = 100, int $offset = 0): array
    {
        $q = DB::table('g2g_product_claims as c')->leftJoin('g2g_product_master as p', 'p.id', '=', 'c.product_master_id')->where('c.sub_institute_id', $tenant);
        if ($productId !== null) {
            $q->where('c.product_master_id', $productId);
        }
        if ($status !== null) {
            $q->where('c.status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('c.id')->offset(max(0, $offset))->limit(max(1, min(200, $limit)))->get(['c.*', 'p.name as product_name']);

        return ['claims' => $rows->map(fn ($r) => $this->present($r))->all(), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'product_master_id' => (int) $row->product_master_id,
            'product_name' => $row->product_name ?? DB::table('g2g_product_master')->where('id', $row->product_master_id)->value('name'),
            'claim' => (string) $row->claim,
            'source_url' => $row->source_url,
            'status' => (string) $row->status,
            'approved_by' => $row->approved_by === null ? null : (int) $row->approved_by,
            'approved_at' => $row->approved_at,
            'created_by' => $row->created_by === null ? null : (int) $row->created_by,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function event(string $type, int $tenant, int $claimId, int $userId, array $payload, string $key): void
    {
        if (! isset(EventCatalogue::SHIPPED[$type])) {
            Log::warning('Outreach claim event type is not registered in EventCatalogue', ['type' => $type]);
        }
        app(EventRecorder::class)->record($type, $tenant, 'g2g_product_claims', $claimId, $userId, $payload, null, $key);
    }
}
