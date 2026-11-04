<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;

/**
 * Fails when the body links to more than config('outreach_gate.max_links') DISTINCT places. A link is a scheme address (http:// or https://), an address that starts
 * www., or a bare domain ending in one of the configured top-level domains (never an e-mail address: the @ rules it out). The same link written twice counts once.
 * A link whose address contains an unsubscribe marker is the unsubscribe link and never counts: the signed one the mailer adds is appended AFTER the gate runs, so
 * this only matters for a template that already carries its own. Pure.
 */
final class LinkCountCheck implements Check
{
    /**
     * @param  list<string>  $unsubscribeMarkers
     * @param  list<string>  $tlds
     */
    public function __construct(private readonly int $max, private readonly array $unsubscribeMarkers, private readonly array $tlds)
    {
    }

    public function key(): string
    {
        return 'link_count';
    }

    public function label(): string
    {
        return 'At most one link';
    }

    /** @return list<string> the distinct links of a text, normalised, the unsubscribe link excluded */
    public function links(string $text): array
    {
        $tld = $this->tlds === [] ? 'com' : implode('|', array_map(fn (string $t) => preg_quote($t, '~'), $this->tlds));
        $found = [];
        $patterns = [
            '~https?://[^\s<>"\'\)\]]+~iu',
            '~(?<![\w@./:-])www\.[^\s<>"\'\)\]]+~iu',
            '~(?<![\w@./:-])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:' . $tld . ')(?![\w@-])(?:/[^\s<>"\'\)\]]*)?~iu',
        ];
        // Blank out each kind as it is found, so a scheme address is not counted again as a bare domain.
        $rest = $text;
        foreach ($patterns as $regex) {
            if (preg_match_all($regex, $rest, $m)) {
                foreach ($m[0] as $hit) {
                    $found[] = $hit;
                }
                $rest = preg_replace($regex, ' ', $rest) ?? $rest;
            }
        }

        $distinct = [];
        foreach ($found as $hit) {
            $link = mb_strtolower(rtrim($hit, '.,;:!?)]}>\'"'));
            $link = (string) preg_replace('~^https?://~', '', $link);
            $link = (string) preg_replace('~^www\.~', '', $link);
            $link = rtrim($link, '/');
            if ($link === '' || $this->isUnsubscribe($link)) {
                continue;
            }
            $distinct[$link] = true;
        }

        return array_keys($distinct);
    }

    private function isUnsubscribe(string $link): bool
    {
        foreach ($this->unsubscribeMarkers as $marker) {
            if ($marker !== '' && str_contains($link, mb_strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    public function run(Draft $draft): CheckResult
    {
        $links = $this->links($draft->body);
        $n = count($links);

        if ($n <= $this->max) {
            return CheckResult::pass($this->key(), $this->label(), $n === 0 ? 'The body has no link.' : "The body has {$n} link" . ($n === 1 ? '' : 's') . ' (the limit is ' . $this->max . ').');
        }

        return CheckResult::fail($this->key(), $this->label(), "The body has {$n} different links (the limit is {$this->max}): " . implode(', ', array_slice($links, 0, 3)) . ($n > 3 ? ' and more' : '') . '.');
    }
}
