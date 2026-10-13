<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock picklist values for the order-to-cash suite, same discipline and
 * same seeding helper shape as 2026_11_24_081200_seed_crm_sales_picklist_values.php.
 *
 * `invoice_stage` keeps the handful of values confirmed directly in legacy
 * source (`Sent`/`Paid`/`Credit Invoice`/`Cancel` - see `Invoice.php`'s own
 * trash()/restore() and the language file's picklist labels), renaming
 * legacy's bare `Cancel` to `Cancelled` to match this app's own grammatical
 * convention elsewhere. `AutoCreated` is deliberately dropped - it only
 * ever gets set by legacy's recurring-invoice cron, which this phase does
 * not build (see crm_invoices' own migration docblock).
 *
 * `so_stage`/`po_stage` have no confirmed legacy value list (the research
 * pass found no seed data in this checkout, only the two postatus literals
 * `Received Shipment`/`Cancelled` that legacy's own PHP special-cases for a
 * stock-ledger side effect this app has no equivalent system for) - these
 * are a reasonable standard procurement/fulfillment set, flagged here for
 * an admin to adjust via the existing Picklist Admin screen if the real
 * legacy install used different labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('crm_picklist_values')) {
            return;
        }

        $now = now();
        $rows = [];

        $seed = function (string $module, string $fieldKey, array $values) use (&$rows) {
            foreach (array_values($values) as $i => $entry) {
                [$value, $color] = is_array($entry) ? $entry : [$entry, null];

                $rows[] = [
                    'module' => $module,
                    'field_key' => $fieldKey,
                    'value' => $value,
                    'label' => $value,
                    'sort_order' => $i + 1,
                    'color' => $color,
                    'is_default' => false,
                    'status' => true,
                ];
            }
        };

        $seed('sales_orders', 'so_stage', [
            ['Draft', 'muted'],
            ['Confirmed', 'primary'],
            ['Processing', 'primary'],
            ['Shipped', 'warning'],
            ['Completed', 'success'],
            ['Cancelled', 'destructive'],
        ]);

        $seed('purchase_orders', 'po_stage', [
            ['Draft', 'muted'],
            ['Pending Approval', 'warning'],
            ['Approved', 'primary'],
            ['Received Shipment', 'success'],
            ['Cancelled', 'destructive'],
        ]);

        $seed('invoices', 'invoice_stage', [
            ['Draft', 'muted'],
            ['Sent', 'primary'],
            ['Paid', 'success'],
            ['Credit Invoice', 'warning'],
            ['Cancelled', 'destructive'],
        ]);

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        foreach (array_chunk($rows, 100) as $chunk) {
            foreach ($chunk as $row) {
                $exists = DB::table('crm_picklist_values')
                    ->where('module', $row['module'])
                    ->where('field_key', $row['field_key'])
                    ->where('value', $row['value'])
                    ->exists();

                if (! $exists) {
                    DB::table('crm_picklist_values')->insert($row);
                }
            }
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('crm_picklist_values')) {
            return;
        }

        DB::table('crm_picklist_values')
            ->whereIn('module', ['sales_orders', 'purchase_orders', 'invoices'])
            ->delete();
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
