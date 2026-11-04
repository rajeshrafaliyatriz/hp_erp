<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\Text;

/** Fails when the subject or body still holds a merge field nobody filled in ({{first_name}}, {first_name}, [First Name], [COMPANY], <first_name>, %FIRSTNAME%, ${name}). Pure. */
final class PlaceholderCheck implements Check
{
    /** @var list<string> compiled regular expressions */
    private array $regexes;

    /** @param list<string> $patterns config('outreach_gate.placeholder_patterns') */
    public function __construct(array $patterns)
    {
        $this->regexes = array_map(fn (string $p) => Text::regex($p), $patterns);
    }

    public function key(): string
    {
        return 'placeholder';
    }

    public function label(): string
    {
        return 'No unfilled merge fields';
    }

    /** @return list<string> every merge field found, once each, in order of appearance; a field inside a bigger one ({name} inside {{name}}) is not listed again */
    public function found(string $text): array
    {
        $spans = [];
        foreach ($this->regexes as $regex) {
            if (preg_match_all($regex, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $at]) {
                    $spans[] = [$at, $at + strlen($hit), trim($hit)];
                }
            }
        }
        // Earliest first, and the longest first among those that start together; then drop whatever lies inside a span already kept.
        usort($spans, fn (array $a, array $b) => [$a[0], $b[1]] <=> [$b[0], $a[1]]);
        $kept = [];
        foreach ($spans as [$start, $end, $hit]) {
            foreach ($kept as [$keptStart, $keptEnd]) {
                if ($start >= $keptStart && $end <= $keptEnd) {
                    continue 2;
                }
            }
            $kept[] = [$start, $end, $hit];
        }

        return array_values(array_unique(array_map(fn (array $k) => $k[2], $kept)));
    }

    public function run(Draft $draft): CheckResult
    {
        $found = $this->found($draft->text());

        return $found === []
            ? CheckResult::pass($this->key(), $this->label(), 'Every merge field is filled in.')
            : CheckResult::fail($this->key(), $this->label(), 'An unfilled merge field is still in the text: ' . implode(', ', array_slice($found, 0, 4)) . (count($found) > 4 ? ' and more' : '') . '.');
    }
}
