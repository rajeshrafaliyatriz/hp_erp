<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Support\Carbon;

/**
 * The ScholarClone definition in use: the day's searches, the hard rules, how long a signal stays fresh, and the block
 * of text the research prompt is given. It reads config/signals_icp.php (`scholar_k12`), the single definition of who
 * buys ScholarClone and what shows that they need it. There is one, for every organisation: nothing here is chosen,
 * stored or edited per organisation any more.
 */
final class IcpPack
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly string $key,
        private readonly array $config,
        public readonly ?int $profileId = null,
        public readonly ?string $name = null,
    ) {
    }

    /** The pack a run uses: always ScholarClone. The arguments stay so older callers keep working. */
    public static function forRun(int $tenantId = 0, ?int $profileId = null): ?self
    {
        return \App\Domain\Signals\Scholar\ScholarClone::pack();
    }

    public static function forTenant(int $tenantId = 0): ?self
    {
        return self::forRun($tenantId);
    }

    /** @return list<self> */
    public static function allActive(int $tenantId = 0): array
    {
        return [self::forRun($tenantId)];
    }

    /** A template (config/signals_icp.php) as an ICP, e.g. to preview it before an organisation copies it. */
    public static function fromTemplate(string $templateKey): ?self
    {
        $cfg = config("signals_icp.{$templateKey}");

        return is_array($cfg) ? new self($templateKey, $cfg, null, (string) ($cfg['label'] ?? $templateKey)) : null;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function productLine(): ?string
    {
        $v = $this->config['product_line'] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }

    public function disqualifier(string $text): ?string
    {
        return (new IcpScorer())->disqualifier($text, $this->config);
    }

    /**
     * Does this organisation sell what we sell (software, AI, analytics, edtech, ERP, LMS, IT services, consulting)? Judged from
     * the ICP's own lists: `vendor_phrases` against its NAME and SECTOR, `vendor_news_phrases` against the first FACT reported.
     * Such an organisation is a competitor or a possible partner and is never a buyer.
     */
    public function looksLikeVendor(string $name, ?string $sector, string $fact): bool
    {
        // A named competitor (config `competitors`) is a seller by definition, whatever its name or the news says.
        return IcpPhrases::matches((array) ($this->config['competitors'] ?? []), $name)
            || IcpPhrases::matches((array) ($this->config['vendor_phrases'] ?? []), trim($name . ' ' . (string) $sector))
            || IcpPhrases::matches((array) ($this->config['vendor_news_phrases'] ?? []), $fact);
    }

    /**
     * Catalogue queries for today. The catalogue is interleaved by source type (news, job, tender, ...) so one
     * run mixes kinds of evidence, then a window of `n` is taken that moves forward each day, so the whole
     * catalogue is covered every few days.
     *
     * @return list<array{id: string, source: string, topic: string, days: int, q: string, domains?: list<string>}>
     */
    public function catalogueForRun(Carbon $now, ?int $n = null): array
    {
        $n ??= (int) ($this->config['per_run']['catalogue'] ?? 3);
        $bySource = [];
        foreach ((array) ($this->config['queries'] ?? []) as $q) {
            $bySource[$q['source']][] = $q;
        }
        $ordered = [];
        while ($bySource !== []) {
            foreach (array_keys($bySource) as $src) {
                $ordered[] = array_shift($bySource[$src]);
                if ($bySource[$src] === []) {
                    unset($bySource[$src]);
                }
            }
        }
        $count = count($ordered);
        if ($count === 0 || $n <= 0) {
            return [];
        }
        $start = ($now->dayOfYear * $n) % $count;
        $out = [];
        for ($i = 0; $i < min($n, $count); $i++) {
            $out[] = $ordered[($start + $i) % $count];
        }

        return $out;
    }

    /** One search per account: its own name in quotes, so the result is about that organisation. @return array{q: string, topic: string, days: int} */
    public function accountQuery(string $name, ?string $city): array
    {
        $template = (string) ($this->config['account_query'] ?? '"{name}" {city}');
        $q = str_replace(['{name}', '{city}'], [str_replace('"', '', $name), (string) $city], $template);

        return ['q' => trim(preg_replace('/\s+/', ' ', $q) ?? $q), 'topic' => 'general', 'days' => (int) ($this->config['account_days'] ?? 30)];
    }

    /** Days a lead of this trigger type stays worth acting on; null = until the item's own closing date. */
    public function decayDays(string $triggerType): ?int
    {
        $map = (array) ($this->config['decay_by_trigger_type'] ?? []);

        return array_key_exists($triggerType, $map) ? $map[$triggerType] : ($map['default'] ?? 90);
    }

    /** True in the months this ICP's buyers change systems. */
    public function inBuyingWindow(Carbon $now): bool
    {
        return in_array($now->month, (array) ($this->config['buying_window_months'] ?? []), true);
    }

    /** The ICP, as instructions for the research model. Every phrase comes from the organisation's own ICP data. */
    public function promptBlock(): string
    {
        $c = $this->config;
        $clean = fn ($v) => trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');
        $list = fn (array $map) => implode("\n", array_map(fn ($k, $v) => '  - ' . $clean($k) . ': ' . $clean($v), array_keys($map), $map));

        $parts = $list((array) ($c['parts'] ?? []));
        $triggers = implode("\n", array_map(
            fn ($k, $t) => '  - ' . $clean($k) . ' (' . $clean($t['source'] ?? '') . '): ' . $clean($t['pain'] ?? '') . (isset($t['part']) ? '; opens ' . $clean($t['part']) : ''),
            array_keys((array) ($c['triggers'] ?? [])), (array) ($c['triggers'] ?? [])
        ));
        $roles = $list((array) ($c['buying_centre'] ?? []));
        $disq = implode(', ', array_map(fn ($d) => $clean($d['label'] ?? ''), (array) ($c['disqualifiers'] ?? [])));
        $regions = implode(', ', array_map($clean, (array) ($c['regions'] ?? [])));
        $mustHave = implode(', ', array_filter(array_map(fn ($r) => $clean($r['prompt'] ?? ''), (array) ($c['must_have'] ?? []))));
        $window = ($c['buying_window_months'] ?? []) === []
            ? ''
            : ($this->inBuyingWindow(Carbon::now())
                ? 'These buyers are in their buying window right now: say so when it applies.'
                : 'Outside these buyers\' usual buying window: do not claim a deadline that the source does not state.');

        $out = "\n\nIDEAL CUSTOMER\n"
            . 'We sell ' . $clean($c['label'] ?? '') . ' to ' . $clean($c['industry_label'] ?? 'organisations') . ($regions !== '' ? " (regions: {$regions})" : '') . ".\n";
        if ($parts !== '') {
            $out .= "What we sell has these parts:\n{$parts}\n";
        }
        if ($triggers !== '') {
            $out .= "A lead is an organisation that shows one of these events, in the source's own words:\n{$triggers}\n";
        }
        if ($disq !== '') {
            $out .= "NEVER return an organisation that is: {$disq}.\n";
        }
        if ($mustHave !== '') {
            $out .= "ALSO leave out an organisation when its source STATES that it has {$mustHave}. When the source does not say, never guess: keep the lead.\n";
        }
        if (! empty($c['guidance'])) {
            $out .= $clean($c['guidance']) . "\n";
        }
        $out .= 'Set "needs_statement" to ONE plain sentence under 20 words that says what the organisation needs and why now, starting with its name, '
            . 'for example: "Northfield is opening a second site and needs one system for both."' . "\n";
        if ($roles !== '') {
            $out .= "For soft_marketing_angle, suggest WHO to approach by role, choosing from the list below (never a named person unless a source names one), and the opening that matches that role:\n{$roles}\n";
        }
        $out .= 'For a tender or notice, set "closing_date" (YYYY-MM-DD) ONLY if the source states it. For a job post, say which role is being hired in what_happened.' . "\n";

        return $out . $window;
    }
}
