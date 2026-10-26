<?php

namespace App\Domain\Signals\Products;

/**
 * Which ScholarClone capability a piece of evidence supports, and how strong a reason that is to buy. Pure text rules, no provider and no database.
 *
 * Tiers (never all called "qualified"):
 *   qualified     direct buying intent for a capability: a procurement notice or an explicit stated requirement
 *   review        a promising trigger that is not a buying statement (a new school, a campus, a training programme): a person decides
 *   informational context only (an association post, an event, general news)
 *   rejected      no capability matched, or the evidence is missing
 *
 * A new affiliation, an association link, an event attendance or another school's purchase is never enough on its own to be "qualified".
 */
final class ProductSignalRules
{
    public const QUALIFIED = 'qualified';
    public const REVIEW = 'review';
    public const INFORMATIONAL = 'informational';
    public const REJECTED = 'rejected';

    /** capability => [direct buying phrases, softer trigger phrases] (lower case) */
    private const RULES = [
        'core' => [
            ['school management system', 'school erp', 'erp for school', 'student information system', 'school administration software', 'fee management software', 'admission management'],
            ['new school', 'new campus', 'new branch', 'newly affiliated', 'affiliation granted', 'school group expansion', 'digitisation', 'digitization'],
        ],
        'learn' => [
            ['learning management system', 'lms for school', 'online assessment platform', 'digital classroom solution', 'smart class tender', 'e-learning platform'],
            ['digital classroom', 'smart classroom', 'online learning', 'digital learning initiative'],
        ],
        'ccb' => [
            ['teacher competency framework', 'teacher training platform', 'teacher capability', 'teacher professional development platform', 'teacher assessment system'],
            ['teacher training programme', 'teacher upskilling', 'teacher appraisal', 'professional development programme', 'cpd programme'],
        ],
        'insight' => [
            ['school analytics', 'school performance dashboard', 'education analytics', 'management information system', 'attendance and assessment reporting', 'udise reporting software'],
            ['compliance reporting', 'performance dashboard', 'data-driven decision', 'reporting requirement'],
        ],
        'compass' => [
            ['career guidance platform', 'career counselling platform', 'aptitude assessment platform', 'career readiness platform'],
            ['career counselling', 'career guidance', 'career readiness', 'aptitude test', 'school counsellor', 'counselling cell'],
        ],
    ];

    /** Phrases that make the evidence an actual procurement or stated requirement. */
    private const INTENT = ['tender', 'rfp', 'request for proposal', 'eoi', 'expression of interest', 'rfq', 'bid', 'invites proposals', 'looking for', 'we are hiring', 'requirement for', 'procure'];

    /**
     * @param  array<string, mixed>  $evidence  text, url (required for anything above "rejected"), date (YYYY-MM-DD or null), source_kind
     * @return array{tier: string, products: list<string>, reason: string, intent: bool}
     */
    public static function assess(array $evidence): array
    {
        $text = mb_strtolower(trim((string) ($evidence['text'] ?? '')));
        if ($text === '' || trim((string) ($evidence['url'] ?? '')) === '') {
            return ['tier' => self::REJECTED, 'products' => [], 'reason' => 'No evidence text or no source address.', 'intent' => false];
        }

        $direct = [];
        $soft = [];
        foreach (self::RULES as $product => [$directPhrases, $softPhrases]) {
            if (self::has($text, $directPhrases)) {
                $direct[] = $product;
            } elseif (self::has($text, $softPhrases)) {
                $soft[] = $product;
            }
        }
        $products = array_values(array_unique(array_merge($direct, $soft)));
        if ($products === []) {
            return ['tier' => self::REJECTED, 'products' => [], 'reason' => 'No ScholarClone capability is supported by this evidence.', 'intent' => false];
        }

        $intent = self::has($text, self::INTENT);
        $kind = (string) ($evidence['source_kind'] ?? '');
        $association = in_array($kind, ['association', 'event'], true);

        if ($direct !== [] && $intent && ! $association) {
            return ['tier' => self::QUALIFIED, 'products' => $direct, 'reason' => 'Stated requirement or procurement for ' . implode(', ', $direct) . '.', 'intent' => true];
        }
        if ($association) {
            return ['tier' => self::INFORMATIONAL, 'products' => $products, 'reason' => 'An association or event mention is context, not buying intent.', 'intent' => false];
        }

        return ['tier' => self::REVIEW, 'products' => $products, 'reason' => 'A trigger for ' . implode(', ', $products) . ' without a stated requirement; needs a person to check.', 'intent' => $intent];
    }

    /** @param list<string> $phrases */
    private static function has(string $text, array $phrases): bool
    {
        foreach ($phrases as $p) {
            if (str_contains($text, $p)) {
                return true;
            }
        }

        return false;
    }
}
