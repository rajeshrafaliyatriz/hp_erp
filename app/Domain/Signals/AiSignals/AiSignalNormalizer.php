<?php

namespace App\Domain\Signals\AiSignals;

/**
 * Turns what a scheduled AI task delivered into the records the existing market importer already understands (docs/signals-market-import-schema.md).
 * Pure: no database, no network, no model.
 *
 * It only REARRANGES what the producer sent:
 *   - finds the list of signals in the common envelope shapes (`{"signals": [...]}`, `{"data": {"opportunities": [...]}}`, a bare list), and
 *     reads JSON the model wrapped in a ```json fence;
 *   - moves a field the producer named differently into the canonical name ("company" -> buyer.name, "url" -> source_url, "date" -> event_date),
 *     but never over a canonical field that is already set;
 *   - lower-cases the enumerated values ("Confirmed", "Search Snippet", "School Group") because models capitalise them.
 * It never invents a value and never fills a required field the producer left out: a record without a date, a source URL, a claim level or a fetch
 * level stays without it and the existing QualificationGate rejects it, with a reason. Nothing here relaxes a rule.
 */
final class AiSignalNormalizer
{
    public const SCHEMA = 'g2g.ai-signals.v1';

    private const LIST_KEYS = ['signals', 'opportunities', 'records', 'items', 'results', 'leads', 'feed'];

    /** canonical field => other names a model tends to use, tried in order. */
    private const ALIASES = [
        'trigger_summary' => ['trigger', 'event', 'signal', 'summary', 'headline', 'title'],
        'what_happened' => ['evidence', 'quote', 'details', 'description', 'facts'],
        'source_url' => ['url', 'link', 'source_link', 'sourceUrl'],
        'source_title' => ['source_name', 'publisher'],
        'event_date' => ['date', 'published_at', 'published', 'announced_on', 'detected_on'],
        'trigger_type' => ['signal_type', 'event_type'],
        'claim_level' => ['claim', 'evidence_claim'],
        'fetch_level' => ['fetch', 'evidence_fetch_level'],
    ];

    private const BUYER_NAME = ['buyer_name', 'company', 'company_name', 'organisation', 'organization', 'organisation_name', 'organization_name', 'account', 'institution', 'school'];

    /** Fields whose value is one of a fixed list: compared lower-case with spaces and hyphens as underscores. */
    private const ENUM_FIELDS = ['trigger_type', 'claim_level', 'fetch_level', 'business_fit', 'entry_point', 'scale', 'context'];

    /**
     * @return array{ok: bool, error: ?string, meta: array{delivery_ref: ?string, provider: ?string, generated_at: ?string, task: ?string, schema: ?string}, records: list<mixed>, canonical_hash: ?string}
     */
    public static function parse(string $raw): array
    {
        $empty = ['delivery_ref' => null, 'provider' => null, 'generated_at' => null, 'task' => null, 'schema' => null];
        $data = self::decode($raw);
        if (! is_array($data)) {
            return ['ok' => false, 'error' => 'The delivery is not valid JSON.', 'meta' => $empty, 'records' => [], 'canonical_hash' => null];
        }

        $list = null;
        $envelope = $data;
        if (array_is_list($data)) {
            $list = $data;
        } else {
            $list = self::findList($data);
            if ($list === null && is_array($data['data'] ?? null)) {
                $list = array_is_list($data['data']) ? $data['data'] : self::findList($data['data']);
                $envelope = $data + $data['data'];
            }
        }
        $meta = [
            'delivery_ref' => self::text($envelope['delivery_id'] ?? ($envelope['delivery_ref'] ?? ($envelope['run_id'] ?? null)), 191),
            'provider' => self::text($envelope['provider'] ?? ($envelope['source'] ?? null), 40),
            'generated_at' => self::text($envelope['generated_at'] ?? ($envelope['run_at'] ?? null), 40),
            'task' => self::text($envelope['task'] ?? ($envelope['task_name'] ?? null), 191),
            'schema' => self::text($envelope['schema'] ?? null, 60),
        ];
        // A "source" that is a URL or a sentence is not a provider name; only a short word we recognise is used as one.
        if ($meta['provider'] !== null && AiSignalProviders::normaliseName($meta['provider']) === null) {
            $meta['provider'] = null;
        }
        if ($list === null) {
            return ['ok' => false, 'error' => 'No list of signals was found. Send {"signals": [ ... ]}.', 'meta' => $meta, 'records' => [], 'canonical_hash' => null];
        }

        return [
            'ok' => true, 'error' => null, 'meta' => $meta,
            'records' => array_map(fn ($r) => is_array($r) ? self::normaliseRecord($r) : $r, array_values($list)),
            'canonical_hash' => hash('sha256', (string) json_encode(self::sortKeys($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** One record in whatever field names the model used, to the import schema. Fields already in the canonical name are never replaced. */
    public static function normaliseRecord(array $r): array
    {
        $out = $r;

        // buyer: a bare string, or an object missing its name, or a flat "company" field.
        $buyer = $r['buyer'] ?? null;
        if (is_string($buyer) && trim($buyer) !== '') {
            $out['buyer'] = ['name' => trim($buyer)];
        } elseif (! is_array($buyer) || self::text($buyer['name'] ?? null) === null) {
            $name = self::first($r, self::BUYER_NAME);
            if ($name !== null) {
                $b = is_array($buyer) ? $buyer : [];
                $b['name'] = $name;
                foreach (['type' => ['buyer_type', 'organisation_type', 'organization_type', 'company_type'], 'state' => ['buyer_state', 'state'], 'website' => ['buyer_website', 'website', 'company_website']] as $field => $names) {
                    if (self::text($b[$field] ?? null) === null && ($v = self::first($r, $names)) !== null) {
                        $b[$field] = $v;
                    }
                }
                $out['buyer'] = $b;
            }
        }

        foreach (self::ALIASES as $canonical => $names) {
            if (self::text($out[$canonical] ?? null) === null && ($v = self::first($r, $names)) !== null) {
                $out[$canonical] = $v;
            }
        }
        // The source link, when the model sent a list of sources instead of one URL.
        if (self::text($out['source_url'] ?? null) === null && is_array($r['sources'] ?? null)) {
            foreach ($r['sources'] as $s) {
                $u = is_array($s) ? self::text($s['url'] ?? ($s['link'] ?? null)) : self::text($s);
                if ($u !== null) {
                    $out['source_url'] = $u;
                    if (is_array($s) && self::text($out['source_title'] ?? null) === null && ($t = self::text($s['title'] ?? null)) !== null) {
                        $out['source_title'] = $t;
                    }

                    break;
                }
            }
        }

        foreach (self::ENUM_FIELDS as $f) {
            if (isset($out[$f]) && is_string($out[$f])) {
                $out[$f] = self::enumValue($out[$f]);
            }
        }
        if (is_array($out['buyer'] ?? null) && isset($out['buyer']['type']) && is_string($out['buyer']['type'])) {
            $out['buyer']['type'] = self::enumValue($out['buyer']['type']);
        }

        return $out;
    }

    private static function enumValue(string $v): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($v)));
    }

    /** First non-blank scalar among the named keys, as trimmed text. */
    private static function first(array $r, array $names): ?string
    {
        foreach ($names as $n) {
            if (($t = self::text($r[$n] ?? null)) !== null) {
                return $t;
            }
        }

        return null;
    }

    private static function text(mixed $v, int $max = 4000): ?string
    {
        if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private static function findList(array $d): ?array
    {
        foreach (self::LIST_KEYS as $k) {
            if (isset($d[$k]) && is_array($d[$k]) && ($d[$k] === [] || array_is_list($d[$k]))) {
                return $d[$k];
            }
        }

        return null;
    }

    /** JSON text, possibly with a byte-order mark or wrapped in a Markdown code fence, to an array; null when it is not JSON. */
    private static function decode(string $raw): mixed
    {
        $t = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);
        $data = json_decode($t, true);
        if (is_array($data)) {
            return $data;
        }
        if (preg_match('/```(?:json)?\s*(.*?)```/si', $t, $m) === 1) {
            $data = json_decode(trim($m[1]), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map([self::class, 'sortKeys'], $v);
        if (! array_is_list($v)) {
            ksort($v);
        }

        return $v;
    }
}
