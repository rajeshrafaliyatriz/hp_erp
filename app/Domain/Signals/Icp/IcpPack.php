<?php

namespace App\Domain\Signals\Icp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * One ICP in use for one organisation: the day's searches, the hard rules, how long a signal stays fresh, and the
 * block of text the research prompt is given. It wraps an ICP configuration that an organisation owns as DATA
 * (g2g_icp_profiles); nothing here knows what industry it is.
 *
 * Where a run gets its ICP, in order:
 *   1. an ACTIVE profile in the database (the one asked for, else the default, else the oldest);
 *   2. a template named in config('signals.icp.scholar_k12_tenants') (a stop-gap so nothing breaks before an
 *      organisation has saved its own profile);
 *   3. none: the generic product-profile research, unchanged.
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

    /** The ICP a run uses, or null for generic research. */
    public static function forRun(int $tenantId, ?int $profileId = null): ?self
    {
        $profiles = self::activeProfiles($tenantId);
        if ($profiles !== []) {
            if ($profileId !== null) {
                foreach ($profiles as $p) {
                    if ($p->profileId === $profileId) {
                        return $p;
                    }
                }

                return null;   // asked for a profile that is not active here: never fall back to another one silently
            }

            return $profiles[0];   // already ordered: default first, then oldest
        }

        return self::legacyTemplate($tenantId);
    }

    /** Backward-compatible name for the single ICP of an organisation. */
    public static function forTenant(int $tenantId): ?self
    {
        return self::forRun($tenantId);
    }

    /** Every ACTIVE ICP of an organisation, default first. Falls back to the stop-gap template. @return list<self> */
    public static function allActive(int $tenantId): array
    {
        $profiles = self::activeProfiles($tenantId);
        if ($profiles !== []) {
            return $profiles;
        }
        $legacy = self::legacyTemplate($tenantId);

        return $legacy === null ? [] : [$legacy];
    }

    /**
     * Has the organisation defined any ICP at all, active or not? An organisation that has ICPs but none active must not
     * fall back to generic research: it has said what it wants researched, and right now that is nothing.
     */
    public static function hasProfiles(int $tenantId): bool
    {
        try {
            return Schema::hasTable('g2g_icp_profiles') && IcpProfile::where('sub_institute_id', $tenantId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** A template (config/signals_icp.php) as an ICP, e.g. to preview it before an organisation copies it. */
    public static function fromTemplate(string $templateKey): ?self
    {
        $cfg = config("signals_icp.{$templateKey}");

        return is_array($cfg) ? new self($templateKey, $cfg, null, (string) ($cfg['label'] ?? $templateKey)) : null;
    }

    /** @return list<self> */
    private static function activeProfiles(int $tenantId): array
    {
        try {
            if (! Schema::hasTable('g2g_icp_profiles')) {
                return [];
            }
            $rows = IcpProfile::where('sub_institute_id', $tenantId)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->get();
        } catch (\Throwable) {
            return [];   // an older database without the table behaves exactly as before
        }

        return $rows->map(fn (IcpProfile $p) => new self((string) $p->key, (array) $p->config, (int) $p->id, (string) $p->name))->all();
    }

    private static function legacyTemplate(int $tenantId): ?self
    {
        if (in_array((string) $tenantId, array_map('strval', (array) config('signals.icp.scholar_k12_tenants', [])), true)) {
            return self::fromTemplate('scholar_k12');
        }

        return null;
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
        return IcpPhrases::matches((array) ($this->config['vendor_phrases'] ?? []), trim($name . ' ' . (string) $sector))
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

        $out = "\n\nIDEAL CUSTOMER (this overrides the generic product profile)\n"
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
            . 'for example: "Northfield Hospital is opening a new wing and needs more nurses and a way to roster them."' . "\n";
        if ($roles !== '') {
            $out .= "For soft_marketing_angle, suggest WHO to approach by role, choosing from the list below (never a named person unless a source names one), and the opening that matches that role:\n{$roles}\n";
        }
        $out .= 'For a tender or notice, set "closing_date" (YYYY-MM-DD) ONLY if the source states it. For a job post, say which role is being hired in what_happened.' . "\n";

        return $out . $window;
    }
}
