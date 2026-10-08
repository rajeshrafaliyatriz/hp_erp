<?php

namespace App\Services\Idms;

use App\Models\Idms\DocumentMaster;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Filters + FULLTEXT search over published (done) documents the caller may see. Never selects extracted_text into the list. */
class IdmsSearchService
{
    public function search(array $params, $user, int $subInstituteId): LengthAwarePaginator
    {
        $perPage = min(max((int) ($params['per_page'] ?? 20), 1), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $query = DocumentMaster::query()
            ->visibleTo($user, $subInstituteId)
            ->where('processing_status', 'done')
            ->select([
                'id', 'sub_institute_id', 'title', 'original_file_name', 'mime_type', 'size',
                'storage_path', 'preview_path', 'current_version', 'document_type', 'category',
                'department_id', 'subject', 'document_date', 'academic_year', 'organization',
                'project', 'lifecycle_status', 'summary', 'confidence', 'tags', 'tag_names',
                'owner_id', 'visibility', 'created_by', 'created_at', 'updated_at',
            ]);

        if (!empty($params['department_id'])) {
            $query->where('department_id', (int) $params['department_id']);
        }
        if (!empty($params['document_type'])) {
            $query->where('document_type', (string) $params['document_type']);
        }
        $year = $params['academic_year'] ?? $params['year'] ?? null;
        if (!empty($year)) {
            $query->where('academic_year', 'LIKE', '%' . $this->like((string) $year) . '%');
        }
        if (!empty($params['lifecycle_status'])) {
            $query->where('lifecycle_status', (string) $params['lifecycle_status']);
        }
        if (!empty($params['tag'])) {
            $query->where('tag_names', 'LIKE', DocumentMaster::jsonListLike(mb_strtolower(trim((string) $params['tag']))));
        }

        $term = trim((string) ($params['q'] ?? $params['search'] ?? ''));
        if ($term !== '') {
            // Boolean-mode operators in user text must not be interpreted.
            $boolean = trim(preg_replace('/[+\-><()~*"@]+/u', ' ', $term));
            $like = '%' . $this->like($term) . '%';

            $query->where(function ($sub) use ($boolean, $like) {
                if ($boolean !== '') {
                    $sub->whereRaw('MATCH(title, original_file_name, tags_text, subject, organization) AGAINST(? IN BOOLEAN MODE)', [$boolean . '*'])
                        ->orWhereRaw('MATCH(extracted_text) AGAINST(? IN BOOLEAN MODE)', [$boolean . '*']);
                }
                $sub->orWhere('title', 'LIKE', $like)->orWhere('original_file_name', 'LIKE', $like);
            });
        }

        $sort = $params['sort'] ?? 'newest';
        match ($sort) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'title' => $query->orderBy('title', 'asc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Snippets: one extra query for THIS page's ids only.
        if ($term !== '' && $paginator->isNotEmpty()) {
            $texts = DB::table('document_master')
                ->whereIn('id', $paginator->pluck('id')->all())
                ->pluck('extracted_text', 'id');

            foreach ($paginator->items() as $item) {
                $item->snippet = $this->snippet((string) ($texts[$item->id] ?? ''), $term);
            }
        }

        return $paginator;
    }

    private function like(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /** Escaped text with only bare <mark> tags: the frontend renders it after its own sanitiser. */
    private function snippet(string $text, string $term, int $radius = 120): string
    {
        if ($text === '') {
            return '';
        }

        $pos = mb_stripos($text, $term);
        if ($pos === false) {
            return htmlspecialchars(mb_substr($text, 0, 160)) . '...';
        }

        $start = max(0, $pos - $radius);
        $length = mb_strlen($term) + ($radius * 2);
        $piece = mb_substr($text, $start, $length);
        $piece = ($start > 0 ? '...' : '') . $piece . ($start + $length < mb_strlen($text) ? '...' : '');

        return preg_replace(
            '/(' . preg_quote(htmlspecialchars($term), '/') . ')/iu',
            '<mark>$1</mark>',
            htmlspecialchars($piece)
        );
    }
}
