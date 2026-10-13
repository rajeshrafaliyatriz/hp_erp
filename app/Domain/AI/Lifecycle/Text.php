<?php

namespace App\Domain\AI\Lifecycle;

/** Small, dependency-free text helpers shared by the planning and selection stages. */
final class Text
{
    private const STOP = [
        'the', 'and', 'for', 'are', 'was', 'were', 'with', 'this', 'that', 'what', 'which', 'who', 'how', 'many',
        'much', 'show', 'give', 'tell', 'list', 'please', 'can', 'you', 'have', 'has', 'from', 'about', 'there',
        'their', 'them', 'any', 'all', 'our', 'me', 'my', 'is', 'in', 'of', 'to', 'on', 'a', 'an', 'it', 'do',
        'does', 'get', 'need', 'want', 'would', 'like', 'make', 'generate', 'create', 'report', 'template',
    ];

    /** Lower-case words with punctuation removed, joined by single spaces. */
    public static function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($value)));
    }

    /**
     * Meaningful words: 3+ characters, no stop words, a trailing plural "s" folded away.
     *
     * @return array<int, string>
     */
    public static function tokens(string $value): array
    {
        $words = [];

        foreach (explode(' ', self::normalise($value)) as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOP, true)) {
                continue;
            }

            $words[] = mb_strlen($word) > 3 && str_ends_with($word, 's') ? mb_substr($word, 0, -1) : $word;
        }

        return array_values(array_unique($words));
    }

    /** Whether the sentence contains the phrase as whole words. */
    public static function containsPhrase(string $message, string $phrase): bool
    {
        $needle = self::normalise($phrase);

        return $needle !== '' && str_contains(' ' . self::normalise($message) . ' ', ' ' . $needle . ' ');
    }
}
