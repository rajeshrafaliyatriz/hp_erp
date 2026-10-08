<?php

namespace App\Domain\Signals\Market;

use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Support\StructuredAi;

/**
 * Entity resolution for buyer organisations ("Dept. of School Education, Punjab" and
 * "DSE Punjab" are one buyer once somebody has said so).
 *
 * Resolution order, exact matches only:
 *   1. the company's normalised name (the same normaliser the web-research pipeline uses),
 *   2. an alias whose strongly-normalised form matches.
 * A fuzzy near-match is only ever REPORTED as a suggestion. It is never merged
 * automatically: merging two buyers wrongly is far harder to undo than leaving two rows.
 */
class BuyerResolver
{
    private const SIMILAR_PERCENT = 85.0;

    /** Abbreviations expanded, then stop-words dropped, so "Dept. of X" == "Department X". */
    private const EXPAND = ['dept' => 'department', 'govt' => 'government', 'gov' => 'government', 'univ' => 'university', 'pvt' => 'private', 'ltd' => 'limited', 'corp' => 'corporation'];

    private const STOP = ['the', 'of', 'and', 'for'];

    /** Aggressive normalisation used for ALIAS matching. */
    public static function strongNormalise(string $name): string
    {
        $name = mb_strtolower($name);
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;

        $words = [];
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $word) {
            $word = self::EXPAND[$word] ?? $word;
            if ($word !== '' && ! in_array($word, self::STOP, true)) {
                $words[] = $word;
            }
        }

        return implode(' ', $words);
    }

    /**
     * Find or create the buyer for one record.
     *
     * @param  array{name:string, type:?string, state:?string, website:?string, aliases:list<string>}  $buyer
     * @return array{company: Company, created: bool, suggestion: ?array{id:int, name:string, similarity:int}, alias_conflicts: list<string>}
     */
    public function resolve(int $tenantId, array $buyer): array
    {
        $display = trim($buyer['name']);
        $plain = StructuredAi::normalise($display);
        $strong = self::strongNormalise($display);

        $company = Company::where('sub_institute_id', $tenantId)->where('normalized_name', $plain)->first()
            ?? $this->byAlias($tenantId, $strong);

        $created = false;
        $suggestion = null;
        $domain = $buyer['website'] ? strtolower(preg_replace('/^www\./', '', (string) parse_url($buyer['website'], PHP_URL_HOST))) : null;

        if ($company === null) {
            $suggestion = $this->suggest($tenantId, $strong);

            $company = Company::create([
                'sub_institute_id' => $tenantId, 'name' => $display, 'normalized_name' => $plain,
                'website' => $buyer['website'], 'domain' => $domain ?: null, 'type' => $buyer['type'], 'state' => $buyer['state'],
                'first_seen_at' => now(), 'last_verified_at' => now(),
            ]);
            $created = true;
        } else {
            // Fill gaps only: an import never overwrites what a person or an earlier run already set.
            $company->forceFill([
                'website' => $company->website ?: $buyer['website'],
                'domain' => $company->domain ?: ($domain ?: null),
                'type' => $company->type ?: $buyer['type'],
                'state' => $company->state ?: $buyer['state'],
                'last_verified_at' => now(),
            ])->save();
        }

        $conflicts = $this->attachAliases($tenantId, $company, array_merge([$display], $buyer['aliases']));

        return ['company' => $company, 'created' => $created, 'suggestion' => $suggestion, 'alias_conflicts' => $conflicts];
    }

    private function byAlias(int $tenantId, string $strong): ?Company
    {
        if ($strong === '') {
            return null;
        }

        $alias = CompanyAlias::where('sub_institute_id', $tenantId)->where('normalized_alias', $strong)->first();

        return $alias ? Company::where('sub_institute_id', $tenantId)->find($alias->company_id) : null;
    }

    /** @return ?array{id:int, name:string, similarity:int} the closest existing buyer, never merged */
    private function suggest(int $tenantId, string $strong): ?array
    {
        $best = null;
        $bestPct = 0.0;

        foreach (Company::where('sub_institute_id', $tenantId)->get(['id', 'name']) as $existing) {
            similar_text($strong, self::strongNormalise($existing->name), $pct);
            if ($pct >= self::SIMILAR_PERCENT && $pct > $bestPct) {
                $best = ['id' => (int) $existing->id, 'name' => $existing->name, 'similarity' => (int) round($pct)];
                $bestPct = $pct;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $names
     * @return list<string> aliases that already belong to a different buyer (left alone)
     */
    private function attachAliases(int $tenantId, Company $company, array $names): array
    {
        $conflicts = [];

        foreach ($names as $name) {
            $strong = self::strongNormalise((string) $name);
            if ($strong === '') {
                continue;
            }

            $existing = CompanyAlias::where('sub_institute_id', $tenantId)->where('normalized_alias', $strong)->first();

            if ($existing === null) {
                CompanyAlias::create(['sub_institute_id' => $tenantId, 'company_id' => $company->id, 'alias' => mb_substr(trim((string) $name), 0, 191), 'normalized_alias' => $strong]);
            } elseif ((int) $existing->company_id !== (int) $company->id) {
                $conflicts[] = (string) $name;
            }
        }

        return $conflicts;
    }
}
