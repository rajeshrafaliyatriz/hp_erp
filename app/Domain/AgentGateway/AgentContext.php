<?php

namespace App\Domain\AgentGateway;

/**
 * Everything the Organisation agent is allowed to know about ONE organisation in ONE run, and nothing else (slice 7, thin).
 *
 * It is built read-only and tenant-scoped (OrgAgentContextBuilder), it is bounded (a character budget and a row cap per section), and every fact in it
 * carries a REF: `{type, id, label, url}`. The refs are the whole anti-hallucination mechanism: the model is told to cite refs for everything it says
 * and proposes, and the Gateway keeps a proposal only when every ref it cites is in THIS object, because the model can only have seen what was sent.
 * A ref the model made up, or one that belongs to another organisation, is not here.
 *
 * The facts' text (signal titles, quotes, notes, names) is UNTRUSTED: it came from the web and from people. It is only ever handed to the model
 * inside the data block that render() produces, never as an instruction.
 */
final class AgentContext
{
    /**
     * @param  list<array{ref: array{type: string, id: string, label: string, url: ?string}, data: array<string, mixed>}>  $facts  in priority order, already cut to the budget
     * @param  list<array{key: string, left: array{type: string, id: int, name: string}, right: array{type: string, id: int, name: string}, basis: string, score: float}>  $linkSuggestions
     * @param  array<string, int>  $counts  what was read, for the audit row
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $orgAccountId,
        public readonly string $orgName,
        public readonly ?int $crmOrganizationId,
        public readonly string $today,
        public readonly array $facts,
        public readonly array $linkSuggestions,
        public readonly array $counts,
    ) {
    }

    public static function key(string $type, string|int $id): string
    {
        return $type . ':' . $id;
    }

    /** @return array{type: string, id: string, label: string, url: ?string}|null the ref as the context holds it, or null when the model was never shown it */
    public function ref(string $type, string|int $id): ?array
    {
        foreach ($this->facts as $fact) {
            if ($fact['ref']['type'] === $type && $fact['ref']['id'] === (string) $id) {
                return $fact['ref'];
            }
        }

        return null;
    }

    public function hasRef(string $type, string|int $id): bool
    {
        return $this->ref($type, $id) !== null;
    }

    /** @return list<string> every ref key the model was shown */
    public function refKeys(): array
    {
        return array_map(fn (array $f) => self::key($f['ref']['type'], $f['ref']['id']), $this->facts);
    }

    /** @return array{key: string, left: array{type: string, id: int, name: string}, right: array{type: string, id: int, name: string}, basis: string, score: float}|null */
    public function suggestion(string $key): ?array
    {
        foreach ($this->linkSuggestions as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    /**
     * The facts as one block of JSON lines, ONE fact per line. JSON, because it escapes quotes and newlines: a signal title with a line break in it cannot
     * start a new line that looks like a fact, and cannot close the block. The block itself is wrapped by OrgAgentPrompt in a boundary the data cannot know.
     */
    public function render(): string
    {
        return implode("\n", array_map(fn (array $f) => $this->line($f), $this->facts));
    }

    /** @param array{ref: array{type: string, id: string, label: string, url: ?string}, data: array<string, mixed>} $fact */
    public function line(array $fact): string
    {
        return (string) json_encode(['ref' => ['type' => $fact['ref']['type'], 'id' => $fact['ref']['id']], 'label' => $fact['ref']['label']] + $fact['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
