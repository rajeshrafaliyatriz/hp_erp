<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Q-F1's substitutable half.
 *
 * NOT A NOTIFICATION SERVICE. It is the product's vocabulary, and notifications
 * are one of three consumers - screen labels and report headings are the others.
 * That is why it lives on its own and is exposed at GET /api/terminology rather
 * than being a private helper inside the dispatcher.
 *
 * RESOLUTION IS TWO LAYERS, GLOBAL THEN TENANT:
 *   sub_institute_id = 0  -> the product's default word
 *   sub_institute_id = N  -> what tenant N calls it instead
 * A tenant row REPLACES the global one for that key. A missing key falls through
 * to the global row, and a key missing from BOTH renders as the key itself rather
 * than as an empty string - a screen reading "job_role" is a visible bug; a screen
 * reading "" is an invisible one.
 */
class TerminologyService
{
    public const GLOBAL_TENANT = 0;
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string, array<string, array{singular:string,plural:string}>> */
    private array $cache = [];

    /**
     * @return array<string, array{singular:string, plural:string}>
     */
    public function map(int $subInstituteId, string $locale = self::DEFAULT_LOCALE): array
    {
        $key = $subInstituteId . '|' . $locale;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $rows = DB::table('g2g_terminology')
            ->whereIn('sub_institute_id', [self::GLOBAL_TENANT, $subInstituteId])
            ->where('locale', $locale)
            // GLOBAL FIRST so the tenant row overwrites it in the loop below.
            // Ordering is the whole override mechanism; do not remove it.
            ->orderByRaw('sub_institute_id = ? DESC', [self::GLOBAL_TENANT])
            ->get(['sub_institute_id', 'term_key', 'singular', 'plural']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->term_key] = ['singular' => $r->singular, 'plural' => $r->plural];
        }

        return $this->cache[$key] = $out;
    }

    /**
     * Substitute {term:key} and {term:key|plural} in a fixed sentence.
     *
     * The tenant supplies the NOUN. It does not supply the sentence, and there is
     * no placeholder that would let it.
     */
    public function apply(string $text, array $terms): string
    {
        return preg_replace_callback(
            '/\{term:([a-z0-9_]+)(\|plural)?\}/i',
            function ($m) use ($terms) {
                $key = strtolower($m[1]);
                $form = isset($m[2]) && $m[2] !== '' ? 'plural' : 'singular';
                // Unknown key renders as the key. Loudly wrong beats silently blank.
                return $terms[$key][$form] ?? $key;
            },
            $text
        );
    }

    /**
     * Every term the product ships, so a tenant configuring overrides can see the
     * full list rather than guessing which keys exist.
     *
     * @return array<int,string>
     */
    public function knownKeys(string $locale = self::DEFAULT_LOCALE): array
    {
        return DB::table('g2g_terminology')
            ->where('sub_institute_id', self::GLOBAL_TENANT)
            ->where('locale', $locale)
            ->orderBy('term_key')
            ->pluck('term_key')
            ->all();
    }
}
