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
     *                source_system?:string, date_from?:string, date_to?:string, owner_id?:int, owner_name?:string, folder_id?:int|string}  $filters
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

        // Relevance when there's something to rank, newest-first otherwise -
        // automatic, not a user-facing toggle (a "sort by relevance" control
        // would be meaningless with no query typed, and vice versa). Reuses
        // booleanModeTerm() rather than having applyTermMatch() expose its
        // own computed value - cheap (pure string work, no query) and keeps
        // that method's signature untouched.
        $boolean = $term !== '' ? $this->booleanModeTerm($term) : '';

        $rows = $query
            ->when($boolean !== '', function ($q) use ($boolean) {
                // Sum of both MATCH() scores, so a hit in the body ranks
                // alongside a hit in the title rather than one silently
                // dominating regardless of where the term actually matched.
                $q->orderByRaw(
                    'MATCH(title, original_file_name, subject) AGAINST (? IN BOOLEAN MODE) '
                    .'+ MATCH(extracted_text) AGAINST (? IN BOOLEAN MODE) DESC',
                    [$boolean, $boolean]
                );
            })
            ->orderByDesc('id') // newest-first when there's no term to rank by; stable tiebreaker either way
            ->forPage($page, $perPage)
            ->get([
                'id', 'title', 'original_file_name', 'mime_type', 'size', 'category',
                'document_type', 'department_id', 'document_date', 'period_label',
                'visibility', 'owner_id', 'source_system', 'tags', 'created_at',
                // Which folder it sits in (null = the top level), so a caller can say WHERE a document is.
                'folder_id',
                // Every row here is already filtered to 'done' above, but the
                // column still has to be SELECTED for the frontend to see
                // that - omitting it left `processing_status` undefined on
                // the client, which read as "not done" and showed every
                // already-published document as still "Processing".
                'processing_status',
            ]);

        $snippets = $term !== '' ? $this->snippets($rows->pluck('id')->all(), $term) : [];
        $starred = $this->starredIds($rows->pluck('id')->all(), $callerId);
        $ownerNames = $this->ownerNames($rows->pluck('owner_id')->filter()->unique()->all(), $tenantId);

        $data = $rows->map(function ($row) use ($snippets, $starred, $ownerNames) {
            $data = (array) $row;
            $data['snippet'] = $snippets[$row->id] ?? null;
            $data['starred'] = in_array($row->id, $starred, true);
            // Who the document belongs to, so a list of matches can tell two people apart.
            $data['owner_name'] = $ownerNames[$row->owner_id] ?? null;
            $data['starred'] = in_array($row->id, $starred, true);

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

    /**
     * Which of these ids has THIS caller starred? Same bounded-second-query
     * shape as snippets() above, for the same reason: starring is per-caller
     * metadata that does not belong in the main SELECT's column list - a
     * leftJoin against document_library_stars would force every caller of
     * this method to disambiguate `id` across both tables for a column only
     * one view (the star toggle) ever reads.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function starredIds(array $ids, int $userId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('document_library_stars')
            ->where('user_id', $userId)
            ->whereIn('document_id', $ids)
            ->pluck('document_id')
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
     * Every query word must be present SOMEWHERE in the document - not
     * necessarily the same field as the others, not necessarily adjacent.
     * Words are AND'd together (applyWordMatch() per word), each word
     * independently satisfiable by ANY field (OR'd inside applyWordMatch()).
     *
     * This matters because `document_library` has two separate FULLTEXT
     * indexes - (title, original_file_name, subject) and (extracted_text)
     * alone - and MATCH() requires every boolean-mode word to appear within
     * the SAME index group. A single combined match on the whole phrase (the
     * previous shape of this method) meant a document with word1 only in
     * its title and word2 only in its body matched NEITHER index group, and
     * the literal-phrase LIKE fallback couldn't help either since the words
     * aren't adjacent - a fully-present-but-split-across-fields query
     * silently returned nothing. A single-word query degenerates to exactly
     * one applyWordMatch() call, so this is a strict generalization of the
     * old behavior, not a change to it.
     *
     * Performance trade-off worth naming, not hiding: the WHERE clause now
     * carries ~4 LIKE + 2 MATCH predicates per word instead of a flat total
     * for the whole phrase (searchWords()'s 12-word cap bounds the worst
     * case to ~72 predicates). Same already-accepted "LIKE can't use the
     * FULLTEXT index" cost this class already pays for title/filename/body,
     * just paid per word now - linear and bounded, not N+1, worth
     * revisiting only if document volume grows much larger.
     */
    private function applyTermMatch($query, string $term): void
    {
        $words = $this->searchWords($term);

        if ($words === []) {
            return;
        }

        $query->where(function ($outer) use ($words, $term) {
            $outer->where(function ($allWords) use ($words) {
                foreach ($words as $word) {
                    $allWords->where(function ($perWord) use ($word) {
                        $this->applyWordMatch($perWord, $word);
                    });
                }
            });

            // Whole-phrase fallback, same reasoning as always: a person
            // reads "SSM Science 33 40" as the file called
            // `C10_2026-27_SSM_Science-33-40`. This is inherently about
            // word ADJACENCY (comparing the whole typed phrase against
            // separators-as-spaces), so it can't be decomposed per-word
            // without losing the one thing it adds - a lone word already
            // matches via applyWordMatch()'s own LIKE without this.
            $this->applySpokenMatch($outer, $term);
        });
    }

    /**
     * One query word, independently satisfiable by either FULLTEXT index
     * group (when long/clean enough - see booleanModeTerm()) or an escaped
     * LIKE against title/filename/extracted_text/tags. The LIKE half copies
     * GlobalSearchController::escapeLike() exactly: this codebase already
     * shipped and fixed the bug where an unescaped `%`/`_` matched every
     * row, and that fix is not optional here either.
     *
     * The LIKE fallback (not just the MATCH clause) is what makes a short
     * word or a MariaDB default FULLTEXT stopword ("the", "and", "with",
     * ...) findable at all - MATCH() alone silently drops any word under
     * innodb_ft_min_token_size (3 chars, unconfigured here) and the default
     * stopword list, for every one of these columns including tags.
     *
     * tags is longText, JSON-encoded at the application level (see the
     * migration) - a plain substring LIKE against that encoded text is as
     * correct here as it is for every other free-text column in this
     * method. Currently a no-op in production (nothing writes to
     * document_library.tags yet), but costs nothing to include and starts
     * working the moment something does.
     */
    private function applyWordMatch($query, string $word): void
    {
        $boolean = $this->booleanModeTerm($word);
        $like = '%' . $this->escapeLike($word) . '%';

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
                ->orWhere('original_file_name', 'like', $like)
                ->orWhere('extracted_text', 'like', $like)
                ->orWhere('tags', 'like', $like);
        });
    }

    /**
     * The way a person reads a file name: "SSM Science 33 40" is
     * `C10_2026-27_SSM_Science-33-40`. Names are compared with underscores,
     * hyphens and dots read as spaces, so a file is found by what it is
     * called, not by which separator its uploader happened to use. Only
     * widens a match; it never narrows one.
     */
    private function applySpokenMatch($query, string $term): void
    {
        $spoken = trim((string) preg_replace('/[_\-.\s]+/', ' ', mb_strtolower($term)));

        if ($spoken === '') {
            return;
        }

        $spokenLike = '%' . $this->escapeLike($spoken) . '%';

        foreach (['title', 'original_file_name'] as $column) {
            $query->orWhereRaw("REPLACE(REPLACE(REPLACE(LOWER({$column}), '_', ' '), '-', ' '), '.', ' ') like ?", [$spokenLike]);
        }
    }

    /**
     * Query words, whitespace-split, capped so a pasted paragraph can't blow
     * up the WHERE clause into dozens of AND'd groups - 12 is already far
     * beyond what a search BOX input realistically needs.
     *
     * @return string[]
     */
    private function searchWords(string $term): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($term)) ?: [], fn ($w) => $w !== ''));

        return array_slice($words, 0, 12);
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

        $words = $this->searchWords($term);
        $out = [];

        foreach ($rows as $row) {
            $text = (string) ($row->extracted_text ?? '');

            if ($text === '') {
                $out[$row->id] = null;

                continue;
            }

            [$matched, $position] = $this->firstMatch($text, $term, $words);

            if ($position === false) {
                $out[$row->id] = null;

                continue;
            }

            $start = max(0, $position - 80);
            $excerpt = mb_substr($text, $start, 240);
            $highlighted = preg_replace(
                '/' . preg_quote($matched, '/') . '/i',
                '<mark>$0</mark>',
                $excerpt
            );

            $out[$row->id] = ($start > 0 ? '…' : '') . trim((string) $highlighted) . '…';
        }

        return $out;
    }

    /**
     * The whole typed phrase first - the best excerpt, when it genuinely
     * appears adjacent in the text - otherwise the earliest-positioned
     * individual search word that IS present. applyTermMatch() treats every
     * word as independently satisfiable by any field, so a document can
     * match with no single position containing every word together;
     * without this fallback such a real match would show no excerpt at all.
     * Highlights only the one word found, not every matched word at once -
     * a deliberate scope boundary.
     *
     * @param  string[]  $words
     * @return array{0: string, 1: int|false}
     */
    private function firstMatch(string $text, string $term, array $words): array
    {
        $position = mb_stripos($text, $term);

        if ($position !== false) {
            return [$term, $position];
        }

        $best = null;

        foreach ($words as $word) {
            $wordPosition = mb_stripos($text, $word);

            if ($wordPosition !== false && ($best === null || $wordPosition < $best[1])) {
                $best = [$word, $wordPosition];
            }
        }

        return $best ?? ['', false];
    }
}
