<?php

namespace App\Domain\Signals\Market;

/**
 * Is this "organisation" really a category ("system integrators", "schools", "Government") rather than a named body? Pure.
 *
 * A name is generic when every word is a generic noun, sector word, nationality or place word. A named public body written as
 * "Government of India" / "Ministry of Education" is NOT generic: it names one body. "Government" alone is.
 */
final class GenericName
{
    private const STOP = ['of', 'the', 'and', 'in', 'for', 'a', 'an', 'to', 'all', 'various', 'many', 'some', 'other', 'several', 'top', 'leading', 'major', 'local', 'new'];

    private const WORDS = [
        'system', 'systems', 'integrator', 'integrators', 'si', 'sis', 'school', 'schools', 'college', 'colleges', 'university', 'universities',
        'institution', 'institutions', 'institute', 'institutes', 'company', 'companies', 'firm', 'firms', 'vendor', 'vendors', 'partner', 'partners',
        'reseller', 'resellers', 'distributor', 'distributors', 'enterprise', 'enterprises', 'business', 'businesses', 'organisation', 'organisations',
        'organization', 'organizations', 'agency', 'agencies', 'department', 'departments', 'ministry', 'ministries', 'government', 'governments',
        'govt', 'authority', 'authorities', 'body', 'bodies', 'sector', 'industry', 'industries', 'startup', 'startups', 'provider', 'providers',
        'service', 'services', 'solution', 'solutions', 'consultant', 'consultants', 'consultancy', 'consultancies', 'contractor', 'contractors',
        'buyer', 'buyers', 'customer', 'customers', 'client', 'clients', 'player', 'players', 'operator', 'operators', 'manufacturer', 'manufacturers',
        'bank', 'banks', 'hospital', 'hospitals', 'retailer', 'retailers', 'telecom', 'telco', 'telcos', 'it', 'ict', 'tech', 'technology',
        'public', 'private', 'state', 'central', 'federal', 'national', 'regional', 'municipal', 'education', 'educational', 'healthcare', 'digital',
        'software', 'hardware', 'cloud', 'network', 'networking', 'security', 'smart', 'city', 'cities', 'district', 'districts', 'board', 'boards',
        'psu', 'psus', 'ngo', 'ngos', 'smb', 'smbs', 'msme', 'msmes', 'edtech', 'fintech', 'unnamed', 'unknown', 'undisclosed', 'n/a', 'na', 'none',
        'indian', 'india', 'global', 'international', 'asian', 'asia', 'european', 'europe', 'american', 'african', 'africa', 'gulf', 'middle', 'east',
        'us', 'usa', 'uk', 'uae', 'apac', 'emea', 'bhutan',
    ];

    /** States and cities: "schools in Gujarat" is a category, but "Delhi Public School" (singular head) names one school. */
    private const LOCAL_PLACES = ['gujarat', 'maharashtra', 'karnataka', 'kerala', 'delhi', 'mumbai', 'bengaluru', 'chennai'];

    /** Public-body words that name ONE body when followed by "of <something specific>". */
    private const BODY_HEADS = ['government', 'ministry', 'department', 'govt', 'municipal corporation', 'commissionerate', 'directorate', 'board'];

    public static function isGeneric(string $name): bool
    {
        $n = trim(mb_strtolower($name));
        $n = trim((string) preg_replace('/[^\p{L}\p{N}\/\s&-]+/u', ' ', $n));
        $n = trim((string) preg_replace('/\s+/u', ' ', $n));
        if ($n === '') {
            return true;
        }

        // "Government of India", "Ministry of Education": a named body.
        foreach (self::BODY_HEADS as $head) {
            if (preg_match('/^' . preg_quote($head, '/') . ' of (.+)$/u', $n, $m) === 1) {
                $rest = array_values(array_diff(preg_split('/\s+/u', $m[1]) ?: [], self::STOP));

                return $rest === [];
            }
        }

        $tokens = array_values(array_filter(preg_split('/\s+/u', $n) ?: [], fn ($t) => $t !== '' && ! in_array($t, self::STOP, true)));
        if ($tokens === []) {
            return true;
        }
        $hasLocal = false;
        foreach ($tokens as $t) {
            if (in_array($t, self::LOCAL_PLACES, true)) {
                $hasLocal = true;
            } elseif (! in_array($t, self::WORDS, true)) {
                return false; // a distinctive word: it names someone
            }
        }
        if ($hasLocal && ! str_ends_with((string) end($tokens), 's')) {
            return false; // place + singular head ("Delhi Public School") reads as one named body
        }

        return true;
    }
}
