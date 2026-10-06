<?php

namespace App\Services;

use App\Models\GammaApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gamma presentation generation.
 *
 * The previous frontend called Gamma from a Next.js route and blocked for up to
 * four minutes polling for the render. That work moves here, but stays
 * asynchronous: `createGeneration` returns Gamma's generationId immediately and
 * the client polls `status` - no request ever holds a worker for the full
 * render.
 */
class GammaService
{
    /**
     * Resolve the API key for a tenant.
     *
     * Per-tenant keys in `gamma_api` win (that table exists precisely so each
     * sub-institute can bring its own Gamma account); the env key is the
     * fallback. Only active rows are considered.
     */
    public function resolveApiKey($subInstituteId = null): ?string
    {
        if ($subInstituteId) {
            $tenantKey = GammaApi::where('sub_institute_id', $subInstituteId)
                ->where('status', 1)
                ->whereNotNull('key')
                ->value('key');

            if (!empty($tenantKey)) {
                return $tenantKey;
            }
        }

        $fallback = config('gamma.api_key');

        return !empty($fallback) ? $fallback : null;
    }

    public function isConfigured($subInstituteId = null): bool
    {
        return $this->resolveApiKey($subInstituteId) !== null;
    }

    /**
     * Start a presentation render.
     *
     * @param  string  $inputText  The outline Gamma turns into slides.
     * @return array{generationId: string}
     */
    public function createGeneration(string $inputText, int $slideCount, $subInstituteId = null): array
    {
        $apiKey = $this->requireKey($subInstituteId);
        $defaults = config('gamma.defaults');

        $payload = [
            'inputText' => $inputText,
            'textMode' => $defaults['text_mode'],
            'format' => $defaults['format'],
            'numCards' => $slideCount,
            'cardSplit' => $defaults['card_split'],
            'additionalInstructions' => $defaults['additional_instructions'],
            'exportAs' => $defaults['export_as'],
            'textOptions' => $defaults['text_options'],
            'imageOptions' => $defaults['image_options'],
            'cardOptions' => $defaults['card_options'],
            'sharingOptions' => $defaults['sharing_options'],
        ];

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->timeout((int) config('gamma.request_timeout'))
            ->acceptJson()
            ->asJson()
            ->post($this->url('/generations'), $payload);

        if ($response->failed()) {
            Log::error('Gamma generation request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Gamma rejected the generation request (HTTP ' . $response->status() . ').'
            );
        }

        $generationId = $response->json('generationId');

        if (!$generationId) {
            throw new RuntimeException('Gamma did not return a generationId.');
        }

        return ['generationId' => (string) $generationId];
    }

    /**
     * Poll a render.
     *
     * @return array{status: string, generationId: string, gammaUrl: ?string, exportUrl: ?string}
     */
    public function getGeneration(string $generationId, $subInstituteId = null): array
    {
        $apiKey = $this->requireKey($subInstituteId);

        $response = Http::withHeaders(['x-api-key' => $apiKey])
            ->timeout((int) config('gamma.request_timeout'))
            ->acceptJson()
            ->get($this->url('/generations/' . $generationId));

        if ($response->failed()) {
            Log::error('Gamma poll failed', [
                'generationId' => $generationId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Could not read the generation status from Gamma (HTTP ' . $response->status() . ').'
            );
        }

        return [
            'status' => (string) ($response->json('status') ?? 'pending'),
            'generationId' => $generationId,
            'gammaUrl' => $response->json('gammaUrl'),
            'exportUrl' => $response->json('exportUrl'),
        ];
    }

    private function requireKey($subInstituteId): string
    {
        $apiKey = $this->resolveApiKey($subInstituteId);

        if (!$apiKey) {
            throw new RuntimeException(
                'Gamma is not configured. Add a key for this institute under gamma-api, or set GAMMA_API_KEY.'
            );
        }

        return $apiKey;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('gamma.base_url'), '/') . $path;
    }
}
