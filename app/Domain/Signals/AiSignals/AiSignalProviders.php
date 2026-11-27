<?php

namespace App\Domain\Signals\AiSignals;

/**
 * The AI sources that deliver finished signals to G2G. G2G never runs these models for this feature: the organisation's own scheduled task in each
 * platform produces the signals and delivers them. A source is an ordinary row of g2g_data_connectors whose `type` is `ai_<key>`.
 */
final class AiSignalProviders
{
    public const LABELS = ['claude' => 'Claude', 'gpt' => 'ChatGPT / GPT', 'deepseek' => 'DeepSeek', 'gemini' => 'Gemini'];

    /** What a producer may call itself in a payload, mapped to our key. */
    private const ALIASES = ['anthropic' => 'claude', 'chatgpt' => 'gpt', 'openai' => 'gpt', 'gpt' => 'gpt', 'google' => 'gemini', 'claude' => 'claude', 'deepseek' => 'deepseek', 'gemini' => 'gemini'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function typeFor(string $key): string
    {
        return 'ai_' . $key;
    }

    /** @return list<string> every connector `type` that is an AI source */
    public static function types(): array
    {
        return array_map([self::class, 'typeFor'], self::keys());
    }

    public static function isAiType(?string $type): bool
    {
        return $type !== null && in_array($type, self::types(), true);
    }

    public static function keyFromType(?string $type): ?string
    {
        return self::isAiType($type) ? substr((string) $type, 3) : null;
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? ucfirst($key);
    }

    /** The `import_source` stored on every signal a source delivers: how the signal list tells where it came from. */
    public static function sourceLabel(string $key): string
    {
        return 'ai:' . $key;
    }

    public static function keyFromSourceLabel(?string $importSource): ?string
    {
        if ($importSource === null || ! str_starts_with($importSource, 'ai:')) {
            return null;
        }
        $key = substr($importSource, 3);

        return isset(self::LABELS[$key]) ? $key : null;
    }

    /** "anthropic", "ChatGPT", "openai" ... to our key; null when it is not one we know. */
    public static function normaliseName(?string $name): ?string
    {
        $n = strtolower(trim((string) $name));
        $n = preg_replace('/[^a-z]+/', '', $n) ?? '';

        return self::ALIASES[$n] ?? null;
    }
}
