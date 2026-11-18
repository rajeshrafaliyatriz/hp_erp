<?php

namespace App\Domain\Ask;

/**
 * What one lookup returned (slice 8, Ask): rows, "not permitted", or nothing. A tool never throws for a missing record or a missing right: those are
 * results, and the answer says so in words.
 *
 *   ok             the lookup ran (possibly with no rows: "I found nothing" is an answer)
 *   not_permitted  the caller's profile may not open this kind of record: no row was read
 *
 * `withheld` lists the kinds of record (in words: "leads") the lookup could NOT look at for this caller, whether it was the main thing asked for or a
 * related list under a record that was opened. `searched` says in plain words what was looked up, for the server's own "nothing found" sentence.
 * `total` is how many records matched when more than the cap exist (the rows are then only the first ones).
 */
final class ToolResult
{
    public const OK = 'ok';

    public const NOT_PERMITTED = 'not_permitted';

    /**
     * @param  list<AskRow>  $rows
     * @param  list<string>  $withheld
     * @param  array<string, mixed>  $args  the arguments as validated
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $args,
        public readonly string $status,
        public readonly array $rows = [],
        public readonly array $withheld = [],
        public readonly ?int $total = null,
        public readonly string $searched = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
