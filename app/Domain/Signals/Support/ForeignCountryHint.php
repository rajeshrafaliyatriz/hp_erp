<?php

namespace App\Domain\Signals\Support;

/**
 * Says, from text that is IN the source, whether a candidate belongs to a country other than India. Pure, no network, no database.
 *
 * It is deliberately conservative, because wrongly rejecting a real Indian buyer costs more than keeping a doubtful one marked "unconfirmed":
 * a candidate is outside India only when its text names another country AND never names India, an Indian state or a major Indian city.
 * A country that is part of an organisation's own name ("Bank of America", "Singapore Airlines") counts as a mention like any other, so such a name with
 * no Indian word in its text is treated as outside; an Indian organisation's page almost always names India or a state somewhere.
 */
final class ForeignCountryHint
{
    /** Countries and city-states other than India that appear in Indian business news. */
    private const COUNTRIES = [
        'singapore', 'united states', 'usa', 'u s a', 'u s', 'america', 'united kingdom', 'u k', 'uk', 'britain', 'england', 'scotland', 'wales', 'ireland', 'bhutan', 'nepal',
        'bangladesh', 'sri lanka', 'pakistan', 'afghanistan', 'maldives', 'myanmar', 'uae', 'united arab emirates', 'dubai', 'abu dhabi', 'saudi arabia', 'qatar', 'kuwait',
        'oman', 'bahrain', 'australia', 'new zealand', 'canada', 'germany', 'france', 'netherlands', 'switzerland', 'sweden', 'norway', 'denmark', 'finland', 'spain', 'italy',
        'japan', 'china', 'hong kong', 'taiwan', 'south korea', 'malaysia', 'indonesia', 'thailand', 'vietnam', 'philippines', 'south africa', 'nigeria', 'kenya', 'egypt',
        'ghana', 'ethiopia', 'tanzania', 'brazil', 'mexico', 'argentina', 'israel', 'turkey', 'russia',
    ];

    /** Indian states, union territories and major cities: any of these means "this is about India". */
    private const INDIA = [
        'india', 'indian', 'bharat', 'andhra pradesh', 'arunachal pradesh', 'assam', 'bihar', 'chhattisgarh', 'goa', 'gujarat', 'haryana', 'himachal pradesh', 'jharkhand', 'karnataka',
        'kerala', 'madhya pradesh', 'maharashtra', 'manipur', 'meghalaya', 'mizoram', 'nagaland', 'odisha', 'orissa', 'punjab', 'rajasthan', 'sikkim', 'tamil nadu', 'telangana', 'tripura',
        'uttar pradesh', 'uttarakhand', 'west bengal', 'delhi', 'new delhi', 'chandigarh', 'puducherry', 'jammu', 'kashmir', 'ladakh', 'lakshadweep', 'andaman', 'daman', 'diu', 'dadra',
        'mumbai', 'pune', 'nagpur', 'ahmedabad', 'surat', 'vadodara', 'rajkot', 'gandhinagar', 'bengaluru', 'bangalore', 'chennai', 'hyderabad', 'kolkata', 'jaipur', 'lucknow', 'bhopal',
        'indore', 'patna', 'noida', 'gurugram', 'gurgaon', 'kochi', 'thiruvananthapuram', 'bhubaneswar', 'ranchi', 'raipur', 'guwahati', 'dehradun', 'visakhapatnam', 'coimbatore',
        'gov in', 'nic in',
    ];

    /** @return list<string> the other-country terms found in the text (each once) */
    public static function foreignMentions(string $text): array
    {
        $norm = self::norm($text);
        $found = [];
        foreach (self::COUNTRIES as $c) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($c, '/') . '(?![a-z0-9])/', $norm) === 1) {
                $found[] = $c;
            }
        }

        return $found;
    }

    /** Country-code domains of other countries, and the bare US government / military domains. A host alone never rejects a candidate (a UK site can report on an Indian firm). */
    private const FOREIGN_TLDS = ['sg', 'uk', 'au', 'ae', 'bt', 'ca', 'de', 'fr', 'nz', 'za', 'lk', 'bd', 'np', 'pk', 'my', 'id', 'th', 'vn', 'ph', 'jp', 'cn', 'hk', 'kr', 'us', 'ie', 'nl', 'ch', 'se', 'no', 'dk', 'fi', 'es', 'it', 'sa', 'qa', 'kw', 'om', 'bh', 'ng', 'ke', 'eg', 'br', 'mx', 'il', 'tr', 'ru'];

    public static function foreignHost(string $host): bool
    {
        $host = strtolower(trim($host, ". \t"));
        if ($host === '') {
            return false;
        }
        if (preg_match('/(^|\.)(gov|mil)$/', $host) === 1) {
            return true;   // the bare .gov / .mil are the United States
        }
        $tld = substr(strrchr($host, '.') ?: '', 1);

        return $tld !== '' && in_array($tld, self::FOREIGN_TLDS, true);
    }

    public static function namesIndia(string $text): bool
    {
        $norm = self::norm($text);
        foreach (self::INDIA as $i) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($i, '/') . '(?![a-z0-9])/', $norm) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Names another country and never India (or an Indian state or city). Empty text is never "outside". */
    public static function outsideIndia(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        return self::foreignMentions($text) !== [] && ! self::namesIndia($text);
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;

        return ' ' . trim($s) . ' ';
    }
}
