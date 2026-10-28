<?php

namespace App\Domain\Signals\Run;

/**
 * How a run's closing save is protected. Pure: the caller passes the save, the fallback and the logger as callables, so the behaviour is the same
 * on every environment and is testable without a database.
 *
 * Never turns a failure into a quiet success: if the full summary cannot be saved, the run is closed as FAILED with a short, honest message and
 * the real error goes to the internal log in full. If even that fails, the original exception is thrown.
 */
final class RunFinalizer
{
    public const STAGE_MESSAGE_MAX = 255;

    /**
     * @param  callable(): mixed  $save  the full summary save
     * @param  callable(string, string): mixed  $fallback  (code, message): closes the run as failed with minimal columns
     * @param  callable(string, array): void  $log  (event, context): the internal log
     * @return string 'saved' | 'closed_as_failed'
     *
     * @throws \Throwable the original error when even the fallback cannot be saved
     */
    public static function persist(callable $save, callable $fallback, callable $log): string
    {
        try {
            $save();

            return 'saved';
        } catch (\Throwable $e) {
            $log('signals.run_summary_not_saved', ['exception' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 400)]);
            try {
                $fallback('run_summary_not_saved', 'The run finished, but its summary could not be saved. An administrator should check the server log.');
            } catch (\Throwable $second) {
                $log('signals.run_close_failed', ['exception' => get_class($second), 'message' => mb_substr($second->getMessage(), 0, 400)]);

                throw $e;
            }

            return 'closed_as_failed';
        }
    }

    /**
     * Drops attributes whose column does not exist yet (stage_stats before its migration) and says so, so the caller can log it. Nothing is
     * reported as persisted when it was not.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: array<string, mixed>, 1: list<string>} [attributes to save, names that were dropped]
     */
    public static function withoutMissingColumns(array $attributes, bool $hasStageStats): array
    {
        $dropped = [];
        if (! $hasStageStats && array_key_exists('stage_stats', $attributes)) {
            unset($attributes['stage_stats']);
            $dropped[] = 'stage_stats';
        }

        return [$attributes, $dropped];
    }

    /** True when a stage message is longer than the column and will be cut: the full text is then logged, never lost. */
    public static function willTruncate(?string $message): bool
    {
        return $message !== null && mb_strlen($message) > self::STAGE_MESSAGE_MAX;
    }
}
