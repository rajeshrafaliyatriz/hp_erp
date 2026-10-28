<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock picklist values for the Support phase, same discipline and same
 * seeding-helper shape as the prior phases' own seed migrations.
 *
 * `tickets.status/priority/severity/category` and `service_contracts.
 * tracking_unit/contract_status/contract_type/priority` values are
 * recovered from legacy's language files and corroborating code (not
 * guessed) - flagged for an admin to verify/adjust via the existing
 * Picklist Admin screen since this repo has no live legacy-DB access.
 * Some ServiceContracts groupings (contract_status/contract_type) are
 * inferred from label-file ordering with only partial code corroboration,
 * per the research that grounded this phase's plan - treat as a reasonable
 * starting set, not a guaranteed-complete original list.
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

        $seed('tickets', 'status', [
            ['Open', 'primary'],
            ['Wait For Response', 'warning'],
            ['Closed', 'success'],
        ]);
        $seed('tickets', 'priority', [
            ['Normal', 'muted'],
            ['High', 'warning'],
            ['Urgent', 'destructive'],
        ]);
        $seed('tickets', 'severity', [
            ['Minor', 'muted'],
            ['Major', 'warning'],
            ['Feature', 'primary'],
            ['Critical', 'destructive'],
        ]);
        $seed('tickets', 'category', ['Big Problem', 'Small Problem', 'Other Problem']);

        $seed('service_contracts', 'tracking_unit', ['Hours', 'Days', 'Incidents']);
        $seed('service_contracts', 'contract_status', [
            ['In Planning', 'muted'],
            ['In Progress', 'primary'],
            ['On Hold', 'warning'],
            ['Complete', 'success'],
            ['Archived', 'muted'],
        ]);
        $seed('service_contracts', 'contract_type', ['Normal', 'Support', 'Administrative', 'Undefined']);
        $seed('service_contracts', 'priority', ['Low', 'Normal', 'High']);

        $seed('faqs', 'category', ['General', 'Product', 'Billing', 'Technical', 'Other']);
        $seed('faqs', 'status', [
            ['Draft', 'muted'],
            ['Published', 'success'],
            ['Obsolete', 'destructive'],
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
            ->whereIn('module', ['tickets', 'service_contracts', 'faqs'])
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
