<?php

namespace App\Domain\AI\Examples\Sources;

/**
 * One module's contribution to the AI Stack's data and examples.
 *
 * A group does two things, both from G2G's own tables and menu:
 *
 *  1. `definitions()` - READ-ONLY data sources, in exactly the shape `ModuleDataSourceCatalog` uses
 *     (`name`, `module`, `label`, `description`, `arguments`, `query`). They appear in the module's
 *     Knowledge Base, Templates, Guardrails and Automations tabs and are what the chat reads.
 *  2. `pages()` - which of the module's PAGES each source belongs to, so the AI Stack can show an example
 *     for the page the user is on.
 *
 * THE RULES EVERY SOURCE FOLLOWS (they are the product's privacy and tenancy rules, not style):
 *  - Scoped to the caller's organisation from `$scope->selectedInstituteId`, always. The scope is never an
 *    argument and nothing a caller passes can widen it. Where a table has no tenant column, it is
 *    reached through one that does.
 *  - Read-only: a query builder, never a write.
 *  - Columns verified against the live schema. Soft-deleted rows excluded where the table has `deleted_at`.
 *  - Rows about individual people are allowed in a source, but the source must not expose anything that
 *    is not already on the page it belongs to (no passwords, tokens, bank or ID numbers, salaries of
 *    others unless the page itself shows them to this role). When in doubt, return counts and statuses.
 *  - Names, ids and values come from the database. Nothing here is a sample.
 */
abstract class SourceGroup
{
    /**
     * Catalogue definitions this group adds.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [];
    }

    /**
     * The module's pages, keyed by the menu row's `access_link` (the route the sidebar opens).
     *
     * Each entry:
     *   sources         string[]  catalogue source names for the page, best first. Empty when the page has no
     *                             data behind it - then `no_data_reason` says why, honestly.
     *   purpose         string    ONE sentence on what the page is for. Words about the page, never data.
     *   action          ?string   a chat action key that works on this page (shown as "try it"), or null.
     *   no_data_reason  ?string   required when `sources` is empty.
     *   module, title   ?string   ONLY for a page that is a real route but not a visible sidebar row (e.g. the Document
     *                             Library, whose menu row is hidden): the ai_modules key it belongs to and its title.
     *
     * @return array<string, array{sources: array<int, string>, purpose: string, action?: ?string, no_data_reason?: ?string}>
     */
    public function pages(): array
    {
        return [];
    }

    protected static function arg(string $key, string $type, string $description, bool $required = false): array
    {
        return ['key' => $key, 'type' => $type, 'description' => $description, 'required' => $required];
    }

    protected static function limitArg(): array
    {
        return self::arg('limit', 'integer', 'Maximum rows to return (1–500, default 200).');
    }

    /** @param array<string, mixed> $arguments */
    protected function int(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string, mixed> $arguments */
    protected function text(array $arguments, string $key): ?string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
