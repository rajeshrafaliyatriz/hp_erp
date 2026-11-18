<?php

namespace App\Domain\Ask;

use App\Domain\AgentGateway\Rejected;
use App\Domain\Ask\Tools\AskTool;

/**
 * What the planner (model call 1) asked for, after the server has judged it (slice 8, Ask). The model proposes; this decides:
 *
 *   - the answer must be a JSON object (anything else is not a plan: the caller treats it as a failed call);
 *   - a lookup whose tool is not one of the five is dropped (`unknown_tool`), whatever name it was given;
 *   - a lookup whose arguments do not validate is dropped (`unknown_argument`, `bad_type`, ...): a key such as `sub_institute_id` or `user_id` rejects the whole call;
 *   - the same lookup twice is dropped (`duplicate`);
 *   - at most `ask.max_tool_calls` (3) lookups are kept; the rest are dropped (`over_limit`);
 *   - a get_record that opens "$N" must point at an EARLIER lookup that was kept (`bad_reference` otherwise).
 *
 * Every dropped lookup is kept in `dropped` with its reason, for the audit trail: a refusal is a row, not an error.
 */
final class AskPlan
{
    /** At most this many raw entries are looked at at all (a model that lists hundreds is not read to the end). */
    private const MAX_READ = 20;

    /**
     * @param  list<array{pos: int, tool: AskTool, args: array<string, mixed>}>  $calls
     * @param  list<array{name: string, args: mixed, reason: string}>  $dropped
     */
    private function __construct(public readonly array $calls, public readonly array $dropped)
    {
    }

    /** @return self|null null when the model's answer is not a JSON object */
    public static function parse(string $text, AskCaller $caller, AskToolRegistry $registry): ?self
    {
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
        $decoded = json_decode($text, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        $raw = $decoded['calls'] ?? [];
        if (! is_array($raw) || ! array_is_list($raw)) {
            return new self([], [['name' => '(calls)', 'args' => null, 'reason' => 'bad_plan']]);
        }

        $max = (int) config('ask.max_tool_calls', 3);
        $calls = [];
        $dropped = [];
        $keptAt = [];   // the model's own position (1-based) => true, for the "$N" references
        $seen = [];

        foreach (array_slice($raw, 0, self::MAX_READ) as $i => $entry) {
            $pos = $i + 1;
            $name = is_array($entry) && is_string($entry['tool'] ?? null) ? $entry['tool'] : '(none)';
            $args = is_array($entry) ? ($entry['args'] ?? []) : null;
            try {
                if (! is_array($entry)) {
                    throw new Rejected('not_an_object', 'A lookup is not a JSON object.');
                }
                $tool = $registry->find($name) ?? throw new Rejected('unknown_tool', "'{$name}' is not a lookup that exists.");
                $valid = $tool->validate($caller, $args);
                if (($valid['from_call'] ?? null) !== null && (! isset($keptAt[$valid['from_call']]) || $valid['from_call'] >= $pos)) {
                    throw new Rejected('bad_reference', 'A "$N" must point at an earlier lookup that was kept.');
                }
                $fingerprint = $name . '|' . json_encode($valid);
                if (isset($seen[$fingerprint])) {
                    throw new Rejected('duplicate', 'The same lookup was asked for twice.');
                }
                if (count($calls) >= $max) {
                    throw new Rejected('over_limit', "At most {$max} lookups are run.");
                }
            } catch (Rejected $r) {
                $dropped[] = ['name' => $name, 'args' => $args, 'reason' => $r->reason];

                continue;
            }
            $seen[$fingerprint] = true;
            $keptAt[$pos] = count($calls);
            $calls[] = ['pos' => $pos, 'tool' => $tool, 'args' => $valid];
        }
        if (count($raw) > self::MAX_READ) {
            $dropped[] = ['name' => '(over_limit)', 'args' => ['entries_not_read' => count($raw) - self::MAX_READ], 'reason' => 'over_limit'];
        }

        return new self($calls, $dropped);
    }

    /** The index in $calls of the lookup the model called number $pos, or null when that one was dropped. */
    public function indexOfPosition(int $pos): ?int
    {
        foreach ($this->calls as $index => $call) {
            if ($call['pos'] === $pos) {
                return $index;
            }
        }

        return null;
    }
}
