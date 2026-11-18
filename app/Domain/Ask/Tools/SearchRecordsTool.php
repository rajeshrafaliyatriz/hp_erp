<?php

namespace App\Domain\Ask\Tools;

use App\Domain\AgentGateway\Rejected;
use App\Domain\Ask\AskCaller;
use App\Domain\Ask\AskRecords;
use App\Domain\Ask\AskRights;
use App\Domain\Ask\AskText;
use App\Domain\Ask\ToolResult;

/**
 * search_records {type?, text}: records whose name, email or number contains what was typed. At most 10 rows in all (the first ones, round-robin across the
 * kinds when no kind is named, so one kind does not crowd out another). A kind the person may not open is not searched: it is named in the result.
 */
final class SearchRecordsTool extends AskTool
{
    public function name(): string
    {
        return 'search_records';
    }

    public function describe(): string
    {
        return 'search_records {"type": "lead" | "contact" | "organization" | "opportunity" (optional: omit to search all four), "text": "a name, an email or a number, 2 to 100 characters"}. '
            . 'Finds records whose name, email or number contains the text. Returns at most 10 records, each with its stage or status and owner.';
    }

    protected function allowed(): array
    {
        return ['type', 'text'];
    }

    protected function normalise(AskCaller $caller, array $args): array
    {
        $type = null;
        if (array_key_exists('type', $args) && $args['type'] !== null && $args['type'] !== '') {
            $type = self::kind($args['type']) ?? throw new Rejected('bad_type', 'type must be lead, contact, organization or opportunity.');
        }
        $text = AskText::clip($args['text'] ?? null, 200) ?? throw new Rejected('bad_text', 'text is required.');
        if (mb_strlen($text) < 2 || mb_strlen($text) > 100) {
            throw new Rejected('bad_text', 'text must be 2 to 100 characters.');
        }

        return ['type' => $type, 'text' => $text];
    }

    public function run(AskCaller $caller, array $args): ToolResult
    {
        $types = $args['type'] !== null ? [$args['type']] : AskRecords::TYPES;
        $cap = (int) config('ask.max_search_rows', 10);
        $lists = [];
        $searched = [];
        $withheld = [];
        $total = 0;
        foreach ($types as $type) {
            if (! AskRights::canRead($caller, $type)) {
                $withheld[] = AskRights::plural($type);

                continue;
            }
            $found = AskRecords::search($caller, $type, $args['text'], $cap);
            $lists[] = $found['rows'];
            $searched[] = AskRights::plural($type);
            $total += $found['total'];
        }
        if ($searched === []) {
            return new ToolResult($this->name(), $args, ToolResult::NOT_PERMITTED, [], $withheld);
        }

        return new ToolResult($this->name(), $args, ToolResult::OK, self::interleave($lists, $cap), $withheld, $total, 'in ' . self::words($searched) . ' matching "' . $args['text'] . '"');
    }

    /** Takes one row from each list in turn until the cap, so the first kind cannot use up every place. @param list<list<\App\Domain\Ask\AskRow>> $lists */
    private static function interleave(array $lists, int $cap): array
    {
        $out = [];
        for ($i = 0; count($out) < $cap; $i++) {
            $took = false;
            foreach ($lists as $list) {
                if (isset($list[$i]) && count($out) < $cap) {
                    $out[] = $list[$i];
                    $took = true;
                }
            }
            if (! $took) {
                break;
            }
        }

        return $out;
    }

    /** @param list<string> $words */
    private static function words(array $words): string
    {
        return count($words) > 1 ? implode(', ', array_slice($words, 0, -1)) . ' and ' . end($words) : $words[0];
    }

    /** The four record kinds by the words a model uses for them; null for anything else. */
    public static function kind(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = strtolower(trim($value));

        return match ($v) {
            'lead', 'leads' => 'lead',
            'contact', 'contacts' => 'contact',
            'organization', 'organisation', 'organizations', 'organisations', 'account' => 'organization',
            'opportunity', 'opportunities', 'deal', 'deals' => 'opportunity',
            default => null,
        };
    }
}
