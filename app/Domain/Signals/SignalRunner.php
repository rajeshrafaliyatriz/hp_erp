<?php

namespace App\Domain\Signals;

use App\Domain\AI\Configuration\AiConfigurationResolver;
use App\Domain\AI\Configuration\ProviderCatalog;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Domain\AI\Support\AiQuotaExceededException;
use App\Domain\Signals\Research\ResearchProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One generation attempt for one organisation: lock, record, collect, analyse,
 * de-duplicate, persist, close the record.
 *
 * Every outcome - including failure and "nothing to analyse" - ends with a closed
 * `g2g_signal_runs` row, so the history answers "did it run and what happened".
 * Existing signals are never touched; a run only adds.
 */
class SignalRunner
{
    public function __construct(
        private readonly SignalDataCollector $collector,
        private readonly SignalGenerator $generator,
        private readonly AiConfigurationResolver $configuration,
        private readonly ProviderCatalog $providers,
    ) {
    }

    /** Whether an AI key is resolvable for this organisation. Never reveals the key. */
    public function aiConfigured(int $tenantId): bool
    {
        try {
            $config = $this->configuration->resolve((string) config('signals.ai_module'), $tenantId);

            return $config->hasKey() && $this->providers->isDriveable($config->provider);
        } catch (\Throwable) {
            return false;
        }
    }

    private function providerName(int $tenantId): ?string
    {
        try {
            return $this->configuration->resolve((string) config('signals.ai_module'), $tenantId)->provider;
        } catch (\Throwable) {
            return null;
        }
    }

    public function run(int $tenantId, string $trigger, ?int $userId = null): SignalRun
    {
        $lock = Cache::lock("signals:run:{$tenantId}", ((int) config('signals.stale_run_minutes', 15)) * 60);

        if (! $lock->get()) {
            return $this->record($tenantId, $trigger, $userId, SignalRun::SKIPPED, 'already_running', 'A generation run is already in progress.');
        }

        $started = microtime(true);
        $run = null;

        try {
            $this->closeStaleRuns($tenantId);

            $run = SignalRun::create([
                'sub_institute_id' => $tenantId,
                'trigger' => $trigger,
                'status' => SignalRun::RUNNING,
                'triggered_by' => $userId,
                'started_at' => now(),
            ]);

            $data = $this->collector->collect($tenantId);
            if ($data === null) {
                return $this->finish($run, $started, SignalRun::SKIPPED, [], 'insufficient_data', 'No active departments to analyse yet.');
            }

            $research = [];
            $provider = ResearchProviderFactory::make();
            if ($provider->isConfigured()) {
                try {
                    $research = $provider->search(['organisation' => $data['organisation']]);
                } catch (\Throwable $e) {
                    // Research is optional; internal signals still run.
                    Log::warning('signals.research_failed', ['tenant' => $tenantId, 'error' => class_basename($e)]);
                }
            }

            $result = $this->generator->generate($tenantId, $data, $research);
            $completion = $result['completion'];

            [$stored, $duplicates] = $this->persist($tenantId, $run->id, $result['signals']);

            $status = ($result['rejected'] > 0 && $stored > 0) ? SignalRun::PARTIAL : SignalRun::SUCCESS;

            return $this->finish($run, $started, $status, [
                'signals_generated' => $stored,
                'signals_duplicate' => $duplicates,
                'signals_rejected' => $result['rejected'],
                'provider' => $completion->provider,
                'model' => $completion->model,
            ]);
        } catch (AiNotConfiguredException $e) {
            return $this->fail($run, $tenantId, $trigger, $userId, $started, 'ai_not_configured', 'The AI provider is not configured for this organisation.');
        } catch (AiQuotaExceededException $e) {
            return $this->fail($run, $tenantId, $trigger, $userId, $started, 'ai_quota_exceeded', 'The AI usage quota has been reached.');
        } catch (\Throwable $e) {
            $failure = AiFailureClassifier::classify($e);

            // Sanitised diagnostic: provider, HTTP status and our own code. Never the
            // exception message (it can echo a prompt), the key, or any header.
            Log::error('signals.run_failed', [
                'tenant' => $tenantId,
                'provider' => $this->providerName($tenantId),
                'http_status' => $failure['http'],
                'code' => $failure['code'],
                'exception' => get_class($e),
            ]);

            return $this->fail($run, $tenantId, $trigger, $userId, $started, $failure['code'], $failure['message']);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array{0: int, 1: int} stored, duplicates
     */
    private function persist(int $tenantId, int $runId, array $signals): array
    {
        $windowStart = now()->subDays(max(1, (int) config('signals.dedupe_window_days', 14)));
        $stored = 0;
        $duplicates = 0;
        $seen = [];

        DB::transaction(function () use ($tenantId, $runId, $signals, $windowStart, &$stored, &$duplicates, &$seen) {
            foreach ($signals as $signal) {
                $fingerprint = self::fingerprint($tenantId, $signal['department_id'], $signal['signal_type'], $signal['title']);

                $exists = isset($seen[$fingerprint]) || Signal::where('sub_institute_id', $tenantId)
                    ->where('fingerprint', $fingerprint)
                    ->where('generated_at', '>=', $windowStart)
                    ->exists();

                if ($exists) {
                    $duplicates++;

                    continue;
                }

                $seen[$fingerprint] = true;
                Signal::create($signal + [
                    'sub_institute_id' => $tenantId,
                    'run_id' => $runId,
                    'fingerprint' => $fingerprint,
                    'status' => Signal::STATUS_NEW,
                    'generated_at' => now(),
                ]);
                $stored++;
            }
        });

        return [$stored, $duplicates];
    }

    public static function fingerprint(int $tenantId, ?int $departmentId, string $type, string $title): string
    {
        $normalised = trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)) ?? '');

        return hash('sha256', implode('|', [$tenantId, $departmentId ?? 0, $type, $normalised]));
    }

    /** @param array<string, mixed> $attributes */
    private function finish(SignalRun $run, float $started, string $status, array $attributes = [], ?string $code = null, ?string $message = null): SignalRun
    {
        $run->fill($attributes + [
            'status' => $status,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ])->save();

        return $run;
    }

    private function fail(?SignalRun $run, int $tenantId, string $trigger, ?int $userId, float $started, string $code, string $message): SignalRun
    {
        if ($run === null) {
            return $this->record($tenantId, $trigger, $userId, SignalRun::FAILED, $code, $message);
        }

        return $this->finish($run, $started, SignalRun::FAILED, [], $code, $message);
    }

    private function record(int $tenantId, string $trigger, ?int $userId, string $status, string $code, string $message): SignalRun
    {
        return SignalRun::create([
            'sub_institute_id' => $tenantId,
            'trigger' => $trigger,
            'status' => $status,
            'triggered_by' => $userId,
            'started_at' => now(),
            'completed_at' => now(),
            'duration_ms' => 0,
            'error_code' => $code,
            'error_message' => $message,
        ]);
    }

    private function closeStaleRuns(int $tenantId): void
    {
        SignalRun::where('sub_institute_id', $tenantId)
            ->where('status', SignalRun::RUNNING)
            ->where('started_at', '<', now()->subMinutes((int) config('signals.stale_run_minutes', 15)))
            ->update([
                'status' => SignalRun::FAILED,
                'error_code' => 'timed_out',
                'error_message' => 'The run did not finish and was closed as stale.',
                'completed_at' => now(),
            ]);
    }
}
