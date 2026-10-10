<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock picklist values for the Sales phase, same discipline as the
 * Marketing seed (`2026_11_20_090600_create_crm_picklist_values_table.php`):
 * recovered from the legacy CRM's language files, flagged for an admin to
 * verify/adjust before go-live since this repo has no live legacy-DB access.
 *
 * `sales_stage` is the one picklist this phase seeds a `color` for - it
 * drives the Opportunity kanban board's column colors, and is editable
 * afterward via the existing Picklist Admin screen like any other value
 * here. Colors are semantic tokens (success/warning/muted/primary/
 * destructive), matching this app's existing Badge/StatusBadge variants -
 * never a raw hex or Tailwind class string, so the frontend stays free to
 * re-theme without a data migration.
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

        $seed('opportunities', 'sales_stage', [
            ['Prospecting', 'muted'],
            ['Qualification', 'muted'],
            ['Needs Analysis', 'primary'],
            ['Value Proposition', 'primary'],
            ['Identify Decision Makers', 'primary'],
            ['Perception Analysis', 'warning'],
            ['Proposal/Quotation', 'warning'],
            ['Negotiation/Review', 'warning'],
            ['Closed Won', 'success'],
            ['Closed Lost', 'destructive'],
        ]);
        $seed('opportunities', 'lead_source', [
            'Cold Call', 'Existing Customer', 'Self Generated', 'Employee', 'Partner',
            'Public Relations', 'Direct Mail', 'Conference', 'Trade Show', 'Web Site',
            'Word of mouth', 'Other',
        ]);
        $seed('opportunities', 'potential_type', ['New Business', 'Existing Business']);
        $seed('opportunities', 'forecast_category', ['Pipeline', 'Best Case', 'Commit', 'Omitted']);

        $seed('quotes', 'quote_stage', ['Draft', 'Sent', 'Accepted', 'Rejected', 'Revised', 'Expired']);

        $seed('products', 'category', [
            'Hardware', 'Software', 'Subscription', 'Consulting', 'Support', 'Training', 'Other',
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
            ->whereIn('module', ['opportunities', 'quotes'])
            ->orWhere(function ($q) {
                $q->where('module', 'products')->where('field_key', 'category');
            })
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
