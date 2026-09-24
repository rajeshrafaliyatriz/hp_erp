<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\AI\AiController;

/**
 * Shared base for the Platform Services API.
 *
 * ── WHY IT EXTENDS `AiController` RATHER THAN RESTATING IT ──────────────────
 *
 * `scope()`, `success()`, `failure()`, `handle()` and `limit()` are exactly what these
 * controllers need, and they are already written, already used by nine controllers, and
 * already the shape the frontend's error handling parses. Copying them would give the
 * platform endpoints a second envelope that has to stay identical to the first by hand —
 * and the first time the two disagree is the first time a platform screen shows a raw
 * status code where an AI screen shows the server's own sentence.
 *
 * The inheritance also carries the rule that matters most: `scope()` reads the tenant
 * from `AiContextHydrator`'s resolved scope and raises when there is none, so a platform
 * route declared without that middleware fails loudly rather than quietly reading a
 * tenant from a query string. That guarantee is worth more than a tidier class name.
 *
 * ── WHAT THIS CLASS ADDS ────────────────────────────────────────────────────
 *
 * Only paging, because the platform reads are lists over tables the AI API never
 * touches. `limit()` is inherited and answers "how many"; `page()` answers "which page",
 * which the AI endpoints have never needed.
 */
abstract class PlatformController extends AiController
{
    /** 1-based page number. Anything below 1 is the first page, not an error. */
    protected function page(\Illuminate\Http\Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }

    /**
     * A paged envelope every platform list returns.
     *
     * `total` is counted before the page is taken, because a list that cannot say how
     * many rows it is part of leaves the reader unable to tell an empty last page from
     * an empty table.
     *
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    protected function paged(array $rows, int $total, int $page, int $limit): array
    {
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
            'has_more' => ($page * $limit) < $total,
        ];
    }
}
