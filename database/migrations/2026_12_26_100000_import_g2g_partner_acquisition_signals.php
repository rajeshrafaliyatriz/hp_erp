<?php

use App\Domain\Signals\Market\MarketImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TENANT_ID = 6;
    private const FIXTURE = 'fixtures/g2g_partner_acquisition_signals.json';

    public function up(): void
    {
        foreach (['g2g_companies', 'g2g_company_opportunities', 'g2g_signal_scan_log'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot import G2G partner signals: required table {$table} is missing. Apply the existing Signals schema migrations first.");
            }
        }

        $path = database_path(self::FIXTURE);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Cannot import G2G partner signals: fixture is missing at database/' . self::FIXTURE);
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $records = $payload['records'] ?? null;
        if (! is_array($records) || count($records) !== 5) {
            throw new RuntimeException('Cannot import G2G partner signals: fixture must contain exactly five records.');
        }

        $result = app(MarketImporter::class)->import(self::TENANT_ID, $records, [
            'source_label' => 'g2g-partner-research',
            'origin' => 'command',
        ]);

        if ($result['rejected'] > 0) {
            throw new RuntimeException(sprintf(
                'G2G partner signal import rejected %d of %d records. Accepted records are deduplicated on retry; inspect the scan log and fix the fixture before rerunning.',
                $result['rejected'],
                $result['received'],
            ));
        }
    }

    public function down(): void
    {
        // Signal rows are customer data and may have been reviewed after import. Never delete them in a schema rollback.
    }
};
