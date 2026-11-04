<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;

/** Fails when the subject or body contains a phrase from config('outreach_gate.banned_phrases'), as a whole phrase, ignoring case and spacing. Pure. */
final class BannedPhraseCheck implements Check
{
    /** @param list<string> $phrases */
    public function __construct(private readonly array $phrases)
    {
    }

    public function key(): string
    {
        return 'banned_phrase';
    }

    public function label(): string
    {
        return 'No banned phrases';
    }

    public function run(Draft $draft): CheckResult
    {
        $text = $draft->text();
        $found = [];
        foreach ($this->phrases as $phrase) {
            $phrase = trim($phrase);
            if ($phrase === '') {
                continue;
            }
            $regex = '~(?<![\p{L}\p{N}])' . preg_replace('/\\\\\s+|\s+/u', '\s+', preg_quote($phrase, '~')) . '(?![\p{L}\p{N}])~iu';
            if (preg_match($regex, $text) === 1) {
                $found[] = $phrase;
            }
        }

        return $found === []
            ? CheckResult::pass($this->key(), $this->label(), 'None of the banned phrases appears.')
            : CheckResult::fail($this->key(), $this->label(), 'Contains ' . implode(', ', array_map(fn (string $p) => '“' . $p . '”', array_slice($found, 0, 5))) . (count($found) > 5 ? ' and ' . (count($found) - 5) . ' more' : '') . '.');
    }
}
