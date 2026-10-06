<?php

namespace App\Domain\AI\Support;

use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Durable audit for the AI & Intelligence layer.
 *
 * The same contract LMS K-12's `AiAuditLogger` carries, writing to G2G's own
 * `ai_audit_logs`. Shaped deliberately like `g2g_audit_log` and `hpbrain_audit_logs`
 * so the three can be read together: an investigation into what the system did
 * about a person should not have to reconcile three vocabularies.
 *
 * WRITES NEVER THROW
 *
 * An audit failure must not roll back the thing being audited. Losing a log line
 * is bad; losing an administrator's credential rotation because the log table was
 * locked is worse. Failures fall back to the application log.
 */
class AiAuditLogger
{
    public const CONFIGURATION_CHANGED = 'ai.configuration.changed';

    public const TEMPLATE_CHANGED = 'ai.template.changed';

    public const POLICY_CHANGED = 'ai.policy.changed';

    public const MODEL_CHANGED = 'ai.model.changed';

    public const GOVERNANCE_REJECTED = 'governance.rejected';

    /**
     * Record an event. Returns the row id, or null if the write could not happen.
     *
     * @param  array<string, mixed>  $options
     */
    public function record(
        string $eventType,
        ?AiRequestScope $scope = null,
        array $options = []
    ): ?int {
        $payload = [
            'request_id' => $options['request_id'] ?? request()?->header('X-Request-Id'),
            'event_type' => mb_substr($eventType, 0, 80),
            'actor_type' => $options['actor_type'] ?? ($scope ? 'user' : 'system'),
            'actor_id' => $options['actor_id'] ?? $scope?->userId,
            'actor_label' => isset($options['actor_label'])
                ? mb_substr((string) $options['actor_label'], 0, 150)
                : null,
            'subject_entity_key' => $options['subject_entity_key'] ?? null,
            'subject_id' => isset($options['subject_id']) && is_numeric($options['subject_id'])
                ? (int) $options['subject_id']
                : null,
            'related_type' => $options['related_type'] ?? null,
            'related_id' => isset($options['related_id']) && is_numeric($options['related_id'])
                ? (int) $options['related_id']
                : null,
            'outcome' => $options['outcome'] ?? 'success',
            'message' => isset($options['message']) ? (string) $options['message'] : null,
            'payload' => isset($options['payload'])
                ? json_encode($this->redact($options['payload']), JSON_UNESCAPED_SLASHES)
                : null,
            'sub_institute_id' => $scope?->selectedInstituteId ?? ($options['sub_institute_id'] ?? null),
            'client_id' => $scope?->clientId ?? ($options['client_id'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            if (! Schema::hasTable('ai_audit_logs')) {
                $this->fallback($eventType, $payload);

                return null;
            }

            return (int) DB::table('ai_audit_logs')->insertGetId($payload);
        } catch (Throwable $exception) {
            $this->fallback($eventType, $payload + ['audit_error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Refusals are logged as `outcome = rejected` rather than as failures, because
     * being refused is the system working, not breaking.
     *
     * @param  array<string, mixed>  $options
     */
    public function recordRejection(
        string $reason,
        ?AiRequestScope $scope = null,
        array $options = []
    ): ?int {
        return $this->record(self::GOVERNANCE_REJECTED, $scope, $options + [
            'outcome' => 'rejected',
            'message' => $reason,
        ]);
    }

    /**
     * Strip anything that should not sit in an audit row in the clear.
     *
     * Audit exists to answer "what happened", not to become a second copy of the
     * sensitive data. `api_key` is on this list, and is also never put into a
     * payload by any caller — two independent reasons a credential cannot reach an
     * audit row.
     */
    private function redact(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sensitive = [
            'password', 'user_password', 'plain_password', 'token', 'api_key',
            'authorization', 'remember_token', 'otp', 'aadhar_no', 'pan_no',
            'account_no', 'ifsc_code', 'key_hash',
        ];

        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    /** @param array<string, mixed> $payload */
    private function fallback(string $eventType, array $payload): void
    {
        Log::channel(config('logging.default'))->info('[ai.audit] ' . $eventType, $payload);
    }
}
