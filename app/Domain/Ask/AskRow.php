<?php

namespace App\Domain\Ask;

/**
 * One record a lookup returned (slice 8, Ask): the REF the answer may cite, `{type, id, label, url}`, and the few facts of the record the model is shown.
 *
 * The refs are the whole anti-hallucination mechanism, as in the Organisation agent (AgentContext): the answerer is told to cite records, and a citation is
 * kept only when its type and id are among the rows it was actually shown. The label and the address of a kept citation are taken from the row here, never
 * from what the model wrote.
 *
 * `data` is UNTRUSTED text in part (a lead's description, a note, a signal's title came from people and from the web) and is only ever handed to the model
 * inside the data block AskPrompt builds.
 */
final class AskRow
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $url,
        public readonly array $data = [],
    ) {
    }

    public static function key(string $type, string|int $id): string
    {
        return $type . ':' . $id;
    }

    public function refKey(): string
    {
        return self::key($this->type, $this->id);
    }

    /** @return array{type: string, id: string, label: string, url: ?string} */
    public function ref(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'label' => $this->label, 'url' => $this->url];
    }

    /** One JSON line: JSON escapes quotes and line breaks, so a value cannot start a new line that looks like another row or close the data block. */
    public function line(): string
    {
        return (string) json_encode(['ref' => ['type' => $this->type, 'id' => $this->id], 'label' => $this->label] + array_filter($this->data, fn ($v) => $v !== null && $v !== []),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
