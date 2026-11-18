<?php

namespace App\Domain\Crm\Leads;

use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product and the organisation account a lead points at, as the lead API shows them: `{id, key, name}` and `{id, name}` (slice 6 contract). Built for ONE
 * organisation: a pointer that is not that organisation's to see (a product of another organisation, an identity of another organisation or a removed one)
 * shows as null, so a stray or planted id never lets one organisation read another's names. A list is served with two queries however long it is
 * (warm() with the page's rows), a single lead with at most two, and a name once read is kept for the rest of the request.
 */
final class LeadLinkNames
{
    /** @var array<int, array{id: int, key: ?string, name: string}|null> */
    private array $products = [];

    /** @var array<int, array{id: int, name: string}|null> */
    private array $accounts = [];

    public function __construct(private readonly int $tenantId) {}

    /** Reads every product and account the rows point at, in one query each. @param iterable<object> $rows */
    public function warm(iterable $rows): void
    {
        $products = [];
        $accounts = [];
        foreach ($rows as $row) {
            $p = (int) ($row->product_master_id ?? 0);
            $a = (int) ($row->org_account_id ?? 0);
            if ($p > 0 && ! array_key_exists($p, $this->products)) {
                $products[$p] = $p;
            }
            if ($a > 0 && ! array_key_exists($a, $this->accounts)) {
                $accounts[$a] = $a;
            }
        }

        if ($products !== []) {
            foreach ($products as $id) {
                $this->products[$id] = null;
            }
            if ($this->tenantId > 0 && Schema::hasTable('g2g_product_master')) {
                foreach (ProductMaster::query()->visibleTo($this->tenantId)->whereIn('id', array_values($products))->get(['id', 'product_key', 'name']) as $m) {
                    $this->products[(int) $m->id] = ['id' => (int) $m->id, 'key' => $m->product_key, 'name' => (string) $m->name];
                }
            }
        }
        if ($accounts !== []) {
            foreach ($accounts as $id) {
                $this->accounts[$id] = null;
            }
            if ($this->tenantId > 0 && Schema::hasTable('g2g_org_accounts')) {
                foreach (DB::table('g2g_org_accounts')->where('sub_institute_id', $this->tenantId)->whereNull('deleted_at')->whereIn('id', array_values($accounts))->get(['id', 'name']) as $a) {
                    $this->accounts[(int) $a->id] = ['id' => (int) $a->id, 'name' => (string) $a->name];
                }
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
            $this->warm([(object) ['product_master_id' => $id]]);
        }

        return $this->products[$id];
    }

    /** @return array{id: int, name: string}|null */
    public function account(?int $id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        if (! array_key_exists($id, $this->accounts)) {
            $this->warm([(object) ['org_account_id' => $id]]);
        }

        return $this->accounts[$id];
    }
}
