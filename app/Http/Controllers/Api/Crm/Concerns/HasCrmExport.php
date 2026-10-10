<?php

namespace App\Http\Controllers\Api\Crm\Concerns;

use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CSV-streaming skeleton, same `Response::stream`/`fputcsv` shape as
 * DepartmentJobRoleExportController - the one real Export precedent in this
 * app. Streamed (not built in memory) so a tenant with thousands of rows
 * doesn't load them all before the first byte goes out.
 */
trait HasCrmExport
{
    /** @param array<string, string> $columns db column => CSV header label, in column order */
    protected function exportCsv(Builder $query, array $columns, string $filename): StreamedResponse
    {
        return response()->stream(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_values($columns));

            $query->orderBy('id')->chunk(500, function ($rows) use ($handle, $columns) {
                foreach ($rows as $row) {
                    fputcsv($handle, array_map(fn ($column) => $row->{$column} ?? '', array_keys($columns)));
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
