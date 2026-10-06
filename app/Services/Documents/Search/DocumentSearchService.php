<?php

namespace App\Services\Documents\Search;

use App\Services\Documents\DocumentAccess;
use Illuminate\Support\Facades\DB;

/**
 * Content search over document_library: MariaDB native FULLTEXT, no new
 * search infrastructure - the same approach the reference implementation
 * (next_lms_erp's IDMS) used, and this app already has the index for (see
 * the `document_library` migration's fulltext columns).
 *
 * This is the literal "upload a document, search a word that appears inside
 * it, find the document" feature: `extracted_text` is in the FULLTEXT index,
 * so a hit on content and a hit on the title are both "the document matched"
 * - which is what a Drive/Photos-style search bar needs to feel instant and
 * correct, not merely a title filter.
 */
class DocumentSearchService
{
    /**
     * @param  array{q?:string, category?:string, document_type?:string, department_id?:int,
     *                source_system?:string, date_from?:string, date_to?:string, owner_id?:int, folder_id?:int|string}  $filters
     * @return array{data: array, total: int}
     */
    public function search(array $filters, int $tenantId, int $callerId, ?int $departmentId, int $page = 1, int $perPage = 24): array
    {
        $query = DB::table('document_library')
            ->whereNull('deleted_at')
            ->where('processing_status', 'done');

        DocumentAccess::visibleTo($query, $callerId, $tenantId, $departmentId);

        $this->applyFilters($query, $filters);

        $term = trim((string) ($filters['q'] ?? ''));

        if ($term !== '') {
            $this->applyTermMatch($query, $term);
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get([
                'id', 'title', 'original_file_name', 'mime_type', 'size', 'category',
                'document_type', 'department_id', 'document_date', 'period_label',
                'visibility', 'owner_id', 'source_system', 'tags', 'created_at',
                // Every row here is already filtered to 'done' above, but the
                // column still has to be SELECTED for the frontend to see
                // that - omitting it left `processing_status` undefined on
                // the client, which read as "not done" and showed every
                // already-published document as still "Processing".
                'processing_status',
            ]);

        $snippets = $term !== '' ? $this->snippets($rows->pluck('id')->all(), $term) : [];

        $data = $rows->map(function ($row) use ($snippets) {
            $data = (array) $row;
            $data['snippet'] = $snippets[$row->id] ?? null;

            return $data;
        })->all();

        return ['data' => $data, 'total' => $total];
    }

    private function applyFilters($query, array $filters): void
    {
        foreach (['category', 'document_type', 'source_system'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (!empty($filters['owner_id'])) {
            $query->where('owner_id', (int) $filters['owner_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('document_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('document_date', '<=', $filters['date_to']);
        }

        /*
         * Presence, not truthiness: `folder_id=0` (the frontend's "Home"/root
         * sentinel) must filter to `whereNull('folder_id')`, which `empty()`
         * would otherwise treat as "no filter at all" and silently ignore -
         * the same trap the other fields above don't have to worry about
         * since none of them has a legitimate falsy value. Omitting the key
         * entirely (not browsing by folder) is what leaves this unfiltered.
         */
        if (array_key_exists('folder_id', $filters)) {
            $folderId = (int) $filters['folder_id'];

            if ($folderId === 0) {
                $query->whereNull('folder_id');
            } else {
                $query->where('folder_id', $folderId);
            }
        }
    }

    /**
     * FULLTEXT when the term is usable as one (3+ chars, boolean-mode safe),
     * OR'd with an escaped LIKE so short terms and partial filenames still
     * match - MATCH() alone silently returns nothing for a 1-2 character
     * term. The LIKE half copies GlobalSearchController::escapeLike() exactly:
     * this codebase already shipped and fixed the bug where an unescaped `%`
     * or `_` matched every row, and that fix is not optional here either.
     */
    private function applyTermMatch($query, string $term): void
    {
        $boolean = $this->booleanModeTerm($term);
        $like = '%' . $this->escapeLike($term) . '%';

        $query->where(function ($q) use ($boolean, $like) {
            if ($boolean !== '') {
                $q->orWhereRaw(
                    'MATCH(title, original_file_name, subject) AGAINST (? IN BOOLEAN MODE)',
                    [$boolean]
                )->orWhereRaw(
                    'MATCH(extracted_text) AGAINST (? IN BOOLEAN MODE)',
                    [$boolean]
                );
            }

            $q->orWhere('title', 'like', $like)
                ->orWhere('original_file_name', 'like', $like);
        });
    }

    /**
     * Each word becomes a required prefix term (`word*`), so "search 'project'
     * finds the document containing it" works for a stem (project/projects/
     * projected) rather than only an exact token match. Raw boolean-mode
     * operators (+-*"<>~()) a user might type are stripped first - this is a
     * search box, not a query language, and passing them through verbatim
     * previously meant a stray `+`/`-`/`"` could make MATCH() throw a syntax
     * error instead of returning results.
     */
    private function booleanModeTerm(string $term): string
    {
        $clean = preg_replace('/[+\-><()~*"@]+/', ' ', $term) ?? $term;
        $words = array_filter(explode(' ', $clean), fn ($w) => mb_strlen($w) >= 2);

        if ($words === []) {
            return '';
        }

        return implode(' ', array_map(fn ($w) => '+' . $w . '*', $words));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * A short, highlighted excerpt around the first match, for the current
     * page of results only - `extracted_text` is deliberately never selected
     * in the list query above (it can be megabytes per row), so this runs a
     * second, targeted query bounded to just these ids.
     *
     * @param  int[]  $ids
     * @return array<int, string|null>
     */
    private function snippets(array $ids, string $term): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('document_library')
            ->whereIn('id', $ids)
            ->get(['id', 'extracted_text']);

        $needle = mb_strtolower($term);
        $out = [];

        foreach ($rows as $row) {
            $text = (string) ($row->extracted_text ?? '');

            if ($text === '') {
                $out[$row->id] = null;

                continue;
            }

            $position = mb_stripos($text, $needle);

            if ($position === false) {
                $out[$row->id] = null;

                continue;
            }

            $start = max(0, $position - 80);
            $excerpt = mb_substr($text, $start, 240);
            $highlighted = preg_replace(
                '/' . preg_quote($term, '/') . '/i',
                '<mark>$0</mark>',
                $excerpt
            );

            $out[$row->id] = ($start > 0 ? '…' : '') . trim((string) $highlighted) . '…';
        }

        return $out;
    }
}
