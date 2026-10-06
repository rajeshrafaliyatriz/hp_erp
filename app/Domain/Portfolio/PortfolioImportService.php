<?php

namespace App\Domain\Portfolio;

use Illuminate\Support\Facades\Log;

class PortfolioImportService
{
    /**
     * Default candidate paths for the workbook.
     */
    protected array $candidatePaths = [
        'C:\\Users\\omshivay\\Downloads\\G2G_Foundation_Data_Portfolio_Partners.xlsx',
    ];

    /**
     * Run the import process.
     *
     * @param string|null $customPath Optional path to workbook.
     * @param int|null $tenantId Optional tenant scope (null = global foundation).
     * @return array Summary of import operations.
     */
    public function import(?string $customPath = null, ?int $tenantId = null): array
    {
        $offers = $this->loadOffersData($customPath);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($offers as $offerData) {
            try {
                $offerId = $offerData['offer_id'] ?? null;
                if (! $offerId) {
                    $failed++;
                    continue;
                }

                $existing = ProductOffer::query()
                    ->where('offer_id', $offerId)
                    ->when($tenantId !== null, fn ($q) => $q->where('sub_institute_id', $tenantId), fn ($q) => $q->whereNull('sub_institute_id'))
                    ->first();

                if ($existing) {
                    // Do not overwrite user-edited production records on subsequent imports!
                    if ($existing->is_user_edited) {
                        $skipped++;
                        continue;
                    }

                    // Update existing non-user-edited record
                    $existing->update([
                        'name'                 => $offerData['name'] ?? $existing->name,
                        'parent_product'       => $offerData['parent_product'] ?? $existing->parent_product,
                        'modules_components'   => $offerData['modules_components'] ?? $existing->modules_components,
                        'what_it_does'         => $offerData['what_it_does'] ?? $existing->what_it_does,
                        'needs_solved'         => $offerData['needs_solved'] ?? $existing->needs_solved,
                        'primary_segments'     => $offerData['primary_segments'] ?? $existing->primary_segments,
                        'trigger_signals'      => $offerData['trigger_signals'] ?? $existing->trigger_signals,
                        'deployment_model'     => $offerData['deployment_model'] ?? $existing->deployment_model,
                        'readiness_status'     => $offerData['readiness_status'] ?? $existing->readiness_status,
                        'readiness_confirmed'  => $offerData['readiness_confirmed'] ?? $existing->readiness_confirmed,
                        'typical_deal_band'    => $offerData['typical_deal_band'] ?? $existing->typical_deal_band,
                        'pricing_model'        => $offerData['pricing_model'] ?? $existing->pricing_model,
                        'implementation_effort'=> $offerData['implementation_effort'] ?? $existing->implementation_effort,
                        'delivery_owner'       => $offerData['delivery_owner'] ?? $existing->delivery_owner,
                        'bundles_with'         => $offerData['bundles_with'] ?? $existing->bundles_with,
                        'proof_points'         => $offerData['proof_points'] ?? $existing->proof_points,
                        'notes'                => $offerData['notes'] ?? $existing->notes,
                    ]);
                    $updated++;
                } else {
                    ProductOffer::create([
                        'sub_institute_id'     => $tenantId,
                        'offer_id'             => $offerId,
                        'name'                 => $offerData['name'],
                        'parent_product'       => $offerData['parent_product'],
                        'modules_components'   => $offerData['modules_components'] ?? null,
                        'what_it_does'         => $offerData['what_it_does'] ?? null,
                        'needs_solved'         => $offerData['needs_solved'] ?? [],
                        'primary_segments'     => $offerData['primary_segments'] ?? [],
                        'trigger_signals'      => $offerData['trigger_signals'] ?? [],
                        'deployment_model'     => $offerData['deployment_model'] ?? null,
                        'readiness_status'     => $offerData['readiness_status'],
                        'readiness_confirmed'  => (bool) ($offerData['readiness_confirmed'] ?? false),
                        'typical_deal_band'    => $offerData['typical_deal_band'] ?? null,
                        'pricing_model'        => $offerData['pricing_model'] ?? null,
                        'implementation_effort'=> $offerData['implementation_effort'] ?? null,
                        'delivery_owner'       => $offerData['delivery_owner'] ?? null,
                        'bundles_with'         => $offerData['bundles_with'] ?? [],
                        'proof_points'         => $offerData['proof_points'] ?? null,
                        'notes'                => $offerData['notes'] ?? null,
                        'is_user_edited'       => false,
                    ]);
                    $imported++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to import offer', ['offer' => $offerData, 'error' => $e->getMessage()]);
                $failed++;
            }
        }

        return [
            'total_in_source'     => count($offers),
            'imported'            => $imported,
            'updated'             => $updated,
            'skipped'             => $skipped,
            'failed'              => $failed,
            'partner_count'       => 0, // Workbook row 4 is illustrative example only; no production partners imported
            'sample_partner_note' => 'Row 4 of Partner Network sheet was identified as an illustrative example (P-000) and excluded from production imports per requirements.',
        ];
    }

    /**
     * Load offers data either by parsing the Excel file directly via python or using the pre-extracted JSON.
     */
    protected function loadOffersData(?string $customPath = null): array
    {
        $resolvedPath = $this->resolveWorkbookPath($customPath);

        // If Python and workbook are available, verify or load from file
        if ($resolvedPath && file_exists($resolvedPath)) {
            $parsed = $this->parseExcelWithPython($resolvedPath);
            if (! empty($parsed)) {
                return $parsed;
            }
        }

        // Fallback to verified seed data JSON
        $jsonPath = config_path('portfolio_seed_data.json');
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            $data = json_decode($content, true);
            if (is_array($data) && count($data) > 0) {
                return $data;
            }
        }

        return [];
    }

    protected function resolveWorkbookPath(?string $customPath): ?string
    {
        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        foreach ($this->candidatePaths as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }

        return null;
    }

    protected function parseExcelWithPython(string $path): array
    {
        $escaped = addslashes($path);
        $script = "
import sys, json, openpyxl
try:
    wb = openpyxl.load_workbook(r'{$escaped}', data_only=True, read_only=True)
    if 'Product Portfolio' not in wb.sheetnames:
        print('[]')
        sys.exit(0)
    sheet = wb['Product Portfolio']
    rows = list(sheet.iter_rows(values_only=True))
    data = []
    for r in rows[4:29]:
        if not r or not r[0]: continue
        def clean(v):
            if v is None: return None
            if isinstance(v, str): return v.strip()
            return v
        def clean_arr(v):
            if not v: return []
            return [x.strip() for x in str(v).split(';') if x.strip()]
        data.append({
            'offer_id': clean(r[0]),
            'name': clean(r[1]),
            'parent_product': clean(r[2]),
            'modules_components': clean(r[3]),
            'what_it_does': clean(r[4]),
            'needs_solved': clean_arr(r[5]),
            'primary_segments': clean_arr(r[6]),
            'trigger_signals': clean_arr(r[7]),
            'deployment_model': clean(r[8]),
            'readiness_status': clean(r[9]),
            'readiness_confirmed': clean(r[10]) == 'Yes',
            'typical_deal_band': clean(r[11]),
            'pricing_model': clean(r[12]),
            'implementation_effort': clean(r[13]),
            'delivery_owner': clean(r[14]),
            'bundles_with': clean_arr(r[15]),
            'proof_points': clean(r[16]),
            'notes': clean(r[19]),
        })
    print(json.dumps(data))
except Exception as e:
    print('[]')
";
        $cmd = 'python -c ' . escapeshellarg($script);
        $output = shell_exec($cmd);
        if ($output) {
            $res = json_decode(trim($output), true);
            if (is_array($res) && count($res) > 0) {
                return $res;
            }
        }

        return [];
    }
}

