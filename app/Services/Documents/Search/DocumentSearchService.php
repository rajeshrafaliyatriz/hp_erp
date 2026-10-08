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
     *                source_system?:string, date_from?:string, date_to?:string, owner_id?:int}  $filters
     * @return array{data: array, total: int}
     */
    public function search(array $filters, int $tenantId, int $callerId, ?int $departmentId, int $page = 1, int $perPage = 24): array
    {
        $query = DB::table('document_library')
            ->whereNull('deleted_at')
            ->where('processing_status', 'done');

        DocumentAccess::visibleTo($query, $callerId, $tenantId, $departmentId);

        // A person's name narrows to that person's documents. Resolved to ids inside the caller's own
        // tenant, then applied on top of the visibility rules above - a name can only ever NARROW what
        // the caller may already see, never reveal a document they could not open.
        $ownerName = trim((string) ($filters['owner_name'] ?? ''));

        if ($ownerName !== '') {
            $ownerIds = $this->ownerIdsByName($ownerName, $tenantId);

            if ($ownerIds === []) {
                return ['data' => [], 'total' => 0];
            }

            $query->whereIn('owner_id', $ownerIds);
        }

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

        $ownerNames = $this->ownerNames($rows->pluck('owner_id')->filter()->unique()->all(), $tenantId);

        $data = $rows->map(function ($row) use ($snippets, $ownerNames) {
            $data = (array) $row;
            $data['snippet'] = $snippets[$row->id] ?? null;
            // Who the document belongs to, so a list of matches can tell two people apart.
            $data['owner_name'] = $ownerNames[$row->owner_id] ?? null;

            return $data;
        })->all();

        return ['data' => $data, 'total' => $total];
    }

    /**
     * The ids of this tenant's users whose full name contains every word of `$name`.
     *
     * @return array<int, int>
     */
    private function ownerIdsByName(string $name, int $tenantId): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', $name) ?: []));

        if ($words === []) {
            return [];
        }

        $users = DB::table('tbluser')->where('sub_institute_id', $tenantId)->whereNull('deleted_at');

        foreach (array_slice($words, 0, 4) as $word) {
            $like = '%' . $this->escapeLike($word) . '%';
            $users->whereRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(middle_name,''),' ',COALESCE(last_name,'')) like ?", [$like]);
        }

        return $users->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, string>
     */
    private function ownerNames(array $ids, int $tenantId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))])
            ->all();
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
