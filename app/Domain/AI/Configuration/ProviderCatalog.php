<?php

namespace App\Domain\AI\Configuration;

/**
 * The providers this platform can be configured to call, and how each is driven.
 *
 * WHAT "SUPPORTED" MEANS HERE
 *
 * A provider is listed with the wire shape that reaches it. G2G has exactly two:
 *
 *   - `gemini`            — Google's own REST shape, `POST /models/{model}:generateContent`,
 *                           which is what the Gemini controllers already speak.
 *   - `openai_compatible` — the chat-completions shape `DeepSeekService` already
 *                           speaks. DeepSeek, OpenRouter, OpenAI, Groq and Mistral
 *                           are the same format with a different base URL, so one
 *                           client reaches all five.
 *
 * `anthropic` is listed with no shape on purpose. Its Messages API is different —
 * system prompt outside the message list, its own content blocks — so an
 * OpenAI-compatible client cannot call it, and pretending otherwise would put a
 * provider in a dropdown that fails on first use. It appears with `shape: null`,
 * the screen shows it as unavailable, and saving against it is refused.
 *
 * WHY A SHAPE AND NOT A CLASS NAME
 *
 * LMS K-12's copy of this file names a driver class per provider, because it has a
 * `ModelClient` interface with three implementations. G2G does not; it has
 * `DeepSeekService` and some Http calls. Naming classes that do not exist would
 * make `isDriveable()` a lie the first time anyone read it, so the honest unit here
 * is the wire format. If G2G later grows a `ModelClient`, the shape is what each
 * implementation is selected by, and nothing above this file changes.
 *
 * `api_type` IS THE BRIDGE TO EXISTING ROWS
 *
 * `ai_api_keys.api_type` is what a credential is tagged with. Each entry declares
 * the value its credentials use, read from `config/ai.php` where that file already
 * knows, so a row written by this screen and a row written by anything else resolve
 * through one lookup.
 */
final class ProviderCatalog
{
    private const SHAPE_GEMINI = 'gemini';

    private const SHAPE_OPENAI = 'openai_compatible';

    /**
     * @var array<string, array{label:string, shape:string|null, base_url:string|null, docs:string}>
     */
    private const PROVIDERS = [
        'gemini' => [
            'label' => 'Google Gemini',
            'shape' => self::SHAPE_GEMINI,
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'docs' => 'https://aistudio.google.com/apikey',
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.deepseek.com/v1',
            'docs' => 'https://platform.deepseek.com/api_keys',
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://openrouter.ai/api/v1',
            'docs' => 'https://openrouter.ai/keys',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.openai.com/v1',
            'docs' => 'https://platform.openai.com/api-keys',
        ],
        'groq' => [
            'label' => 'Groq',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.groq.com/openai/v1',
            'docs' => 'https://console.groq.com/keys',
        ],
        'mistral' => [
            'label' => 'Mistral',
            'shape' => self::SHAPE_OPENAI,
            'base_url' => 'https://api.mistral.ai/v1',
            'docs' => 'https://console.mistral.ai/api-keys',
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude',
            // No shape: the Messages API is not OpenAI-compatible. See the class note.
            'shape' => null,
            'base_url' => 'https://api.anthropic.com/v1',
            'docs' => 'https://console.anthropic.com/settings/keys',
        ],
    ];

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function exists(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    /** Whether a provider can actually be called, as opposed to merely listed. */
    public function isDriveable(string $provider): bool
    {
        return $this->shape($provider) !== null;
    }

    /** `gemini` | `openai_compatible` | null. */
    public function shape(string $provider): ?string
    {
        return self::PROVIDERS[$provider]['shape'] ?? null;
    }

    public function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? $provider;
    }

    public function baseUrl(string $provider): ?string
    {
        // A provider already configured in config/ai.php keeps whatever base URL
        // that file resolves — an estate may point a driver at a proxy — and the
        // constant above is only the fallback for providers it has never heard of.
        $configured = trim((string) config("ai.provider.{$provider}.base_url", ''));

        return $configured !== '' ? $configured : (self::PROVIDERS[$provider]['base_url'] ?? null);
    }

    /**
     * The `ai_api_keys.api_type` value credentials for this provider are tagged with.
     *
     * Falls back to the provider key itself, which is the convention every row
     * written by the AI Providers screen follows.
     */
    public function apiType(string $provider): string
    {
        $configured = trim((string) config("ai.provider.{$provider}.api_type", ''));

        return $configured !== '' ? $configured : $provider;
    }

    /** The env-configured key for this provider, used only as a last-resort fallback. */
    public function envKey(string $provider): ?string
    {
        $value = trim((string) config("ai.provider.{$provider}.api_key", ''));

        return $value !== '' ? $value : null;
    }

    /**
     * The provider's default model, as `config/ai.php` resolves it.
     *
     * Only used when neither the saved configuration nor the catalogue names one,
     * so a provider with no catalogue row still has somewhere to go.
     */
    public function defaultModel(string $provider): ?string
    {
        $value = trim((string) config("ai.provider.{$provider}.model", ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Every provider, shaped for the admin screen's dropdown.
     *
     * @return array<int, array{key:string, label:string, driveable:bool, api_type:string, docs:string}>
     */
    public function all(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $key => $provider) {
            $out[] = [
                'key' => $key,
                'label' => $provider['label'],
                'driveable' => $provider['shape'] !== null,
                'api_type' => $this->apiType($key),
                'docs' => $provider['docs'],
            ];
        }

        return $out;
    }
}
