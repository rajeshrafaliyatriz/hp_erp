<?php

namespace App\Domain\Campaigns;

use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product a campaign points at and the campaign a lead points at, as the APIs show them: `{id, key, name}` and `{id, name}` (slice 9 contract). Built for
 * ONE organisation: a pointer that is not that organisation's to see (a product of another organisation, a campaign of another organisation, a campaign in the
 * Recycle Bin or one deleted for good) shows as null, so a stray or planted id never lets one organisation read another's names. A list is served with one
 * query per kind however long it is (warm() with the page's rows), a single row with at most one, and a name once read is kept for the rest of the request.
 */
final class CampaignNames
{
    /** @var array<int, array{id: int, key: ?string, name: string}|null> */
    private array $products = [];

    /** @var array<int, array{id: int, name: string}|null> */
    private array $campaigns = [];

    public function __construct(private readonly int $tenantId) {}

    /** Reads every product the campaign rows point at (`product_master_id`), in one query. @param iterable<object> $rows */
    public function warmProducts(iterable $rows): void
    {
        $wanted = [];
        foreach ($rows as $row) {
            $id = (int) ($row->product_master_id ?? 0);
            if ($id > 0 && ! array_key_exists($id, $this->products)) {
                $wanted[$id] = $id;
            }
        }
        if ($wanted === []) {
            return;
        }

        foreach ($wanted as $id) {
            $this->products[$id] = null;
        }
        if ($this->tenantId > 0 && Schema::hasTable('g2g_product_master')) {
            foreach (ProductMaster::query()->visibleTo($this->tenantId)->whereIn('id', array_values($wanted))->get(['id', 'product_key', 'name']) as $m) {
                $this->products[(int) $m->id] = ['id' => (int) $m->id, 'key' => $m->product_key, 'name' => (string) $m->name];
            }
        }
    }

    /** Reads every campaign the lead rows point at (`campaign_id`), in one query. @param iterable<object> $rows */
    public function warmCampaigns(iterable $rows): void
    {
        $wanted = [];
        foreach ($rows as $row) {
            $id = (int) ($row->campaign_id ?? 0);
            if ($id > 0 && ! array_key_exists($id, $this->campaigns)) {
                $wanted[$id] = $id;
            }
        }
        if ($wanted === []) {
            return;
        }

        foreach ($wanted as $id) {
            $this->campaigns[$id] = null;
        }
        if ($this->tenantId > 0 && Schema::hasTable('crm_campaigns')) {
            foreach (DB::table('crm_campaigns')->where('sub_institute_id', $this->tenantId)->whereNull('deleted_at')->whereIn('id', array_values($wanted))->get(['id', 'name']) as $c) {
                $this->campaigns[(int) $c->id] = ['id' => (int) $c->id, 'name' => (string) $c->name];
            }
        }
    }

    /** @return array{id: int, key: ?string, name: string}|null */
    public function product(?int $id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        if (! array_key_exists($id, $this->products)) {
            $this->warmProducts([(object) ['product_master_id' => $id]]);
        }

        return $this->products[$id];
    }

    /** @return array{id: int, name: string}|null */
    public function campaign(?int $id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        if (! array_key_exists($id, $this->campaigns)) {
            $this->warmCampaigns([(object) ['campaign_id' => $id]]);
        }

        return $this->campaigns[$id];
    }
}
