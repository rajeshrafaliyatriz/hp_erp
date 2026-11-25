<?php

namespace App\Domain\Signals\Ingestion;

use App\Support\ColumnExists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The per-post decision a connector sync records, so every sync can say what happened to every post it read:
 * analysed or not, qualified or rejected, with which structured reason, and the exact quote when it qualified.
 *
 * Stored on the post's g2g_ingestion_analyses row. Before the additive migration is applied the columns do not exist, and then only the
 * old fields are written (the sync itself works exactly as before).
 */
final class SyncDecision
{
    public const QUALIFIED = 'QUALIFIED_SIGNAL';
    public const OWN_BRAND = 'OWN_BRAND_MARKETING';
    public const NO_EVENT = 'NO_BUYING_EVENT';
    public const AI_FAILED = 'AI_ANALYSIS_FAILED';
    public const INVALID_AI = 'INVALID_AI_RESPONSE';

    /** Every code a report can show, with the sentence the interface uses for it. */
    public const LABELS = [
        'QUALIFIED_SIGNAL' => 'Qualified signal',
        'NO_NAMED_PROSPECT' => 'No named school or organisation',
        'NO_BUYING_EVENT' => 'No buying event or stated need',
        'NO_SUPPORTING_QUOTE' => 'No exact supporting quote in the post',
        'OWN_BRAND_MARKETING' => 'Own-brand marketing post',
        'IRRELEVANT_CONTENT' => 'Irrelevant content',
        'GATE_REJECTED' => 'Rejected by the qualification gate',
        'INVALID_AI_RESPONSE' => 'The AI answer could not be read',
        'AI_ANALYSIS_FAILED' => 'The AI analysis failed',
    ];

    private static ?bool $ready = null;

    public static function columnsReady(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = ColumnExists::has('g2g_ingestion_analyses', 'decision_code')
                    && ColumnExists::has('g2g_ingestion_analyses', 'decision_organisation')
                    && ColumnExists::has('g2g_ingestion_analyses', 'connector_sync_id');
            } catch (\Throwable) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** For tests that create the columns after the first check. */
    public static function forgetColumnCheck(): void
    {
        self::$ready = null;
    }

    /** `comments` and `discovery` posts are other people's words; everything else was written by the connected account itself. */
    public static function originOf(IngestionSource $source): string
    {
        $id = (string) $source->external_content_id;

        return match (true) {
            str_contains($id, '-comment:') => 'comments',
            str_contains($id, '-search:'), str_contains($id, '-discovery:') => 'discovery',
            default => 'own_posts',
        };
    }

    /**
     * A post the AI read and found nothing in, written by the account itself, is the account's own marketing: it has no outside buyer to
     * name. Said as such, instead of the vaguer "no buying event".
     *
     * @param  array{code: string, detail: string, quote: ?string, organisation: ?string}  $outcome
     * @return array{code: string, detail: string, quote: ?string, organisation: ?string}
     */
    public static function refine(IngestionSource $source, array $outcome): array
    {
        if ($outcome['code'] === self::NO_EVENT && self::originOf($source) === 'own_posts') {
            return ['code' => self::OWN_BRAND, 'detail' => 'A post by the connected account itself: its own marketing, with no outside school named as a buyer.'] + $outcome;
        }

        return $outcome;
    }

    /** A plain, secret-free account of why a batch failed. Never the provider's message: only what kind of failure it was. */
    public static function fromThrowable(\Throwable $e): array
    {
        $message = strtolower($e->getMessage());
        $invalid = str_contains($message, 'not valid json');
        $timeout = $e instanceof \Illuminate\Http\Client\ConnectionException || str_contains($message, 'timed out') || str_contains($message, 'curl error 28');

        return [
            'code' => $invalid ? self::INVALID_AI : self::AI_FAILED,
            'detail' => $invalid ? 'The AI answered, but not in a form that could be read. This post is retried on the next sync.'
                : ($timeout ? 'The AI provider did not answer in time. This post is retried on the next sync.'
                    : 'The AI request failed (' . class_basename($e) . '). This post is retried on the next sync.'),
            'quote' => null,
            'organisation' => null,
        ];
    }

    /**
     * Writes one analysis row for a post. `success` rows mean "analysed: do not analyse again"; `failed` rows never block a retry.
     *
     * @param  array{code: string, detail: string, quote: ?string, organisation: ?string}|null  $outcome
     */
    public static function record(int $tenantId, int $syncId, ?int $userId, IngestionSource $source, string $status, ?array $outcome, Carbon $started): IngestionAnalysis
    {
        $attributes = [
            'sub_institute_id' => $tenantId, 'source_id' => $source->id, 'status' => $status,
            'triggered_by' => $userId, 'started_at' => $started, 'completed_at' => now(),
            'duration_ms' => (int) max(0, $started->diffInMilliseconds(now())),
        ];
        if ($status === 'failed' && $outcome !== null) {
            $attributes['error_code'] = mb_substr($outcome['code'], 0, 40);
            $attributes['error_message'] = mb_substr($outcome['detail'], 0, 500);
        }
        if ($outcome !== null) {
            $attributes['findings_count'] = $outcome['code'] === self::QUALIFIED ? 1 : 0;
        }
        if ($outcome !== null && self::columnsReady()) {
            $attributes += [
                'connector_sync_id' => $syncId,
                'decision_code' => mb_substr($outcome['code'], 0, 40),
                'decision_detail' => mb_substr($outcome['detail'], 0, 500),
                'decision_organisation' => $outcome['organisation'] !== null && $outcome['organisation'] !== '' ? mb_substr($outcome['organisation'], 0, 191) : null,
                'decision_quote' => $outcome['quote'] !== null && $outcome['quote'] !== '' ? mb_substr($outcome['quote'], 0, 1000) : null,
            ];
        }
        try {
            return IngestionAnalysis::create($attributes);
        } catch (\Throwable $e) {
            // The decision is explanation, not the work itself: if it cannot be saved, save the analysis without it rather than lose the post.
            Log::warning('signals.sync_decision_not_saved', ['tenant' => $tenantId, 'sync' => $syncId, 'exception' => get_class($e)]);

            return IngestionAnalysis::create(array_intersect_key($attributes, array_flip([
                'sub_institute_id', 'source_id', 'status', 'triggered_by', 'started_at', 'completed_at',
            ])));
        }
    }
}
