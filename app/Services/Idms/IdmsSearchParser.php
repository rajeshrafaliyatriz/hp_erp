<?php

namespace App\Services\Idms;

use App\Models\HrmsDepartment;

/** Natural-language query to structured filters + leftover keywords, using rules only. Shown to the user as removable chips. */
class IdmsSearchParser
{
    public function parse(string $query, int $subInstituteId): array
    {
        $filters = [];
        $chips = [];
        $remainder = $query;

        if (preg_match('/\b(20\d{2})[-–](20\d{2}|\d{2})\b/u', $remainder, $m)) {
            $filters['academic_year'] = $m[0];
            $chips[] = ['field' => 'academic_year', 'label' => 'Year: ' . $m[0], 'value' => $m[0]];
            $remainder = str_replace($m[0], ' ', $remainder);
        } elseif (preg_match('/\b(20\d{2})\b/', $remainder, $m)) {
            $filters['year'] = $m[1];
            $chips[] = ['field' => 'year', 'label' => 'Year: ' . $m[1], 'value' => $m[1]];
            $remainder = str_replace($m[0], ' ', $remainder);
        }

        $departments = HrmsDepartment::where('status', 1)
            ->where(fn ($q) => $q->where('sub_institute_id', $subInstituteId)->orWhereNull('sub_institute_id'))
            ->pluck('department', 'id')
            ->toArray();

        foreach ($departments as $id => $name) {
            $name = trim((string) $name);
            if (mb_strlen($name) > 2 && preg_match('/\b' . preg_quote($name, '/') . '\b/iu', $remainder)) {
                $filters['department_id'] = $id;
                $chips[] = ['field' => 'department', 'label' => 'Dept: ' . $name, 'value' => $id];
                $remainder = preg_replace('/\b' . preg_quote($name, '/') . '\b/iu', ' ', $remainder);
                break;
            }
        }

        foreach (['expired', 'archived', 'active', 'filed'] as $status) {
            if (preg_match('/\b' . $status . '\b/i', $remainder)) {
                $filters['lifecycle_status'] = $status;
                $chips[] = ['field' => 'lifecycle_status', 'label' => 'Status: ' . ucfirst($status), 'value' => $status];
                $remainder = preg_replace('/\b' . $status . '\b/i', ' ', $remainder);
            }
        }

        foreach (config('idms.allowed_document_types', []) as $type) {
            if (preg_match('/\b' . preg_quote($type, '/') . 's?\b/iu', $remainder)) {
                $filters['document_type'] = $type;
                $chips[] = ['field' => 'document_type', 'label' => 'Type: ' . $type, 'value' => $type];
                $remainder = preg_replace('/\b' . preg_quote($type, '/') . 's?\b/iu', ' ', $remainder);
                break;
            }
        }

        $filler = ['/\bfind all\b/i', '/\bfind\b/i', '/\bshow me\b/i', '/\bsearch for\b/i', '/\bget\b/i', '/\blist of\b/i', '/\blist\b/i', '/\bfor\b/i'];
        $keywords = trim(preg_replace('/\s+/', ' ', preg_replace($filler, ' ', $remainder)));

        return [
            'raw_query' => $query,
            'keywords' => $keywords,
            'filters' => $filters,
            'chips' => $chips,
        ];
    }
}
