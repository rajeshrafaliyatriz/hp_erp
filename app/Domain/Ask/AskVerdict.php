<?php

namespace App\Domain\Ask;

/**
 * What the judge made of an answer (AskJudge): the statements that kept a citation, and the counts of what was thrown away.
 */
final class AskVerdict
{
    /** @param list<array{text: string, cites: list<AskRow>}> $statements */
    public function __construct(
        public readonly array $statements,
        public readonly int $returned,
        public readonly int $withheld,
        public readonly int $removedCitations,
    ) {
    }

    /** @return list<array{type: string, id: string, label: string, url: ?string}> the records the kept statements cite, once each, in the order they are first cited */
    public function citations(): array
    {
        $out = [];
        foreach ($this->statements as $statement) {
            foreach ($statement['cites'] as $row) {
                $out[$row->refKey()] ??= $row->ref();
            }
        }

        return array_values($out);
    }
}
