<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\Integrations\IntegrationTester;
use App\Services\Platform\Integrations\SmsIntegrationTester;
use App\Services\Platform\Integrations\SmtpIntegrationTester;
use App\Services\Platform\Integrations\WebhookIntegrationTester;
use App\Services\Platform\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One console over every third-party connection this platform declares.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS CONSOLIDATES, AND WHAT IT DELIBERATELY DOES NOT RE-IMPLEMENT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Before this, "Integration" was four unrelated surfaces (see
 * `config/platform_services.php`'s `integrations` block for the full account). This
 * controller fronts all four PLUS two genuinely new providers, but only ever WRITES
 * for the new ones:
 *
 *   readonly_env    read from config()/env() here, same values
 *                    `SessionController::integrations()` already reports — one
 *                    fact, read twice, never duplicated into a table.
 *   oauth_stub      NangoController's own honest not-configured state, surfaced
 *                    rather than re-implemented.
 *   crud_existing   summarised from `lms_integrations` directly (read-only, right
 *                    here) with a link to LMS Administration & Governance, which
 *                    keeps owning writes to it. Re-implementing that CRUD here
 *                    would be a second place to break tenant isolation on a table
 *                    that already has a working, audited owner.
 *   credential      the only kind this controller writes. Backed by
 *                    `g2g_integration_credentials`, which did not exist before
 *                    this round.
 *
 * ── SECRETS ARE NEVER ECHOED BACK ────────────────────────────────────────────
 *
 * A `type: password` field's saved value is decrypted only long enough to be handed
 * to a tester or merged with a partial update; `index()` and `show()` return
 * `has_value: bool` for it, never the value. Overwriting a password with an empty
 * one is refused as a no-op — see `mergeFields()` — the same "absent means
 * unchanged" rule `WorkflowController::update()` already uses for ordinary fields,
 * applied here to the one kind of field where getting it wrong leaks a secret to
 * the browser instead of merely losing an edit.
 */
class IntegrationController extends PlatformController
{
    private const TABLE = 'g2g_integration_credentials';

    /** @var array<string, class-string<IntegrationTester>> */
    private const TESTERS = [
        'smtp' => SmtpIntegrationTester::class,
        'webhook' => WebhookIntegrationTester::class,
        'sms' => SmsIntegrationTester::class,
    ];

    public function __construct(private readonly PlatformRegistry $registry)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $module = trim((string) $request->input('module', ''));

            $rows = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->get()
                ->keyBy('provider_key');

            $providers = [];

            foreach ($this->registry->integrations() as $key => $meta) {
                // A DECENTRALIZED TAB SEES ONLY ITS OWN MODULE'S PROVIDERS.
                if ($module !== '' && ($meta['module'] ?? null) !== $module) {
                    continue;
                }

                $providers[] = $this->present($key, $meta, $rows->get($key), $scope->selectedInstituteId);
            }

            return $this->success('Integrations.', ['providers' => $providers]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Save a `credential`-kind provider's fields.
     *
     * Validated against the field schema the registry declares for this provider —
     * required-ness and type — so a screen can never save something the registry
     * would not also accept from a hand-crafted request.
     */
    public function upsert(Request $request, string $key): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $meta = $this->registry->integration($key);

            if ($meta === null || ($meta['kind'] ?? null) !== 'credential') {
                return $this->failure('That is not a configurable integration.', 422, [
                    'provider' => ['Unknown or not a credential-kind provider.'],
                ]);
            }

            $existing = $this->row($scope->selectedInstituteId, $key);
            $existingConfig = $existing === null ? [] : $this->decrypt($existing->config);

            $submitted = (array) $request->input('config', []);
            $merged = $this->mergeFields($meta['fields'], $existingConfig, $submitted);

            $problem = $this->validateFields($meta['fields'], $merged);

            if ($problem !== null) {
                return $this->failure($problem, 422, ['config' => [$problem]]);
            }

            $now = now();

            DB::table(self::TABLE)->updateOrInsert(
                ['sub_institute_id' => $scope->selectedInstituteId, 'provider_key' => $key],
                [
                    'status' => 'configured',
                    'config' => Crypt::encryptString(json_encode($merged)),
                    // A save invalidates the last test — the fields just changed,
                    // so the old result no longer describes what is stored.
                    'last_tested_at' => null,
                    'last_test_message' => null,
                    'updated_by' => $this->actor($scope),
                    'updated_at' => $now,
                    'created_by' => $existing->created_by ?? $this->actor($scope),
                    'created_at' => $existing->created_at ?? $now,
                ]
            );

            return $this->success('Saved.', [
                'provider' => $this->present($key, $meta, $this->row($scope->selectedInstituteId, $key), $scope->selectedInstituteId),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Run the real connectivity test — against the SUBMITTED values if given (so
     * "Test connection" works before the first Save), otherwise against whatever is
     * already stored.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THIS IS A REAL NETWORK CALL. SEE `IntegrationTester`.
     * ═══════════════════════════════════════════════════════════════════════
     */
    public function test(Request $request, string $key): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $meta = $this->registry->integration($key);

            if ($meta === null || ($meta['kind'] ?? null) !== 'credential') {
                return $this->failure('That is not a testable integration.', 422, [
                    'provider' => ['Unknown or not a credential-kind provider.'],
                ]);
            }

            $testerClass = self::TESTERS[$key] ?? null;

            if ($testerClass === null) {
                return $this->failure('No connectivity test is wired up for this provider.', 422);
            }

            $existing = $this->row($scope->selectedInstituteId, $key);

            $config = $request->has('config')
                ? $this->mergeFields(
                    $meta['fields'],
                    $existing === null ? [] : $this->decrypt($existing->config),
                    (array) $request->input('config', [])
                )
                : ($existing === null ? null : $this->decrypt($existing->config));

            if ($config === null) {
                return $this->failure('Nothing is saved to test yet. Fill in the fields first.', 422);
            }

            $problem = $this->validateFields($meta['fields'], $config);

            if ($problem !== null) {
                return $this->failure($problem, 422, ['config' => [$problem]]);
            }

            /** @var IntegrationTester $tester */
            $tester = app($testerClass);
            $result = $tester->test($config);

            // Only a row that already exists gets its freshness fields updated — a
            // test run before the first Save has nothing to attach the result to,
            // and the response already carries the result either way.
            if ($existing !== null) {
                DB::table(self::TABLE)->where('id', $existing->id)->update([
                    'status' => $result['ok'] ? 'configured' : 'error',
                    'last_tested_at' => now(),
                    'last_test_message' => mb_substr((string) $result['message'], 0, 500),
                ]);
            }

            return $this->success($result['message'], ['ok' => $result['ok'], 'message' => $result['message']]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $deleted = DB::table(self::TABLE)
                ->where('sub_institute_id', $scope->selectedInstituteId)
                ->where('provider_key', $key)
                ->delete();

            if ($deleted === 0) {
                return $this->failure('Nothing was saved for this integration.', 404);
            }

            return $this->success('Removed.', ['deleted' => $key]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function row(int $tenantId, string $key): ?object
    {
        return DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->where('provider_key', $key)
            ->first();
    }

    /** @return array<string, mixed> */
    private function decrypt(?string $encrypted): array
    {
        if ($encrypted === null || $encrypted === '') {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($encrypted), true) ?: [];
        } catch (Throwable) {
            // A row encrypted under a since-rotated APP_KEY cannot be recovered.
            // Treated as empty rather than fatal — the save form starts blank
            // again, which is honest, rather than 500ing the whole console.
            return [];
        }
    }

    /**
     * Submitted fields merged onto what is already stored — "absent or blank on a
     * `password` field means unchanged", every other field always takes the
     * submitted value even if blank (clearing a non-secret field is a real,
     * intentional action; clearing a password silently is a way to accidentally
     * leave the integration broken without meaning to).
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function mergeFields(array $schema, array $existing, array $submitted): array
    {
        $out = $existing;

        foreach ($schema as $field) {
            $fieldKey = (string) $field['key'];

            if (! array_key_exists($fieldKey, $submitted)) {
                continue;
            }

            $value = $submitted[$fieldKey];

            if (($field['type'] ?? null) === 'password' && (trim((string) $value) === '')) {
                continue;
            }

            $out[$fieldKey] = is_string($value) ? trim($value) : $value;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     */
    private function validateFields(array $schema, array $values): ?string
    {
        foreach ($schema as $field) {
            $fieldKey = (string) $field['key'];
            $label = (string) ($field['label'] ?? $fieldKey);
            $value = $values[$fieldKey] ?? null;
            $required = (bool) ($field['required'] ?? false);

            if ($required && (trim((string) $value) === '')) {
                return "\"{$label}\" is required.";
            }

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'number' && ! is_numeric($value)) {
                return "\"{$label}\" must be a number.";
            }

            if ($type === 'select') {
                $options = (array) ($field['options'] ?? []);

                if (! in_array($value, $options, true)) {
                    return "\"{$label}\" must be one of: " . implode(', ', $options) . '.';
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(string $key, array $meta, ?object $row, int $tenantId): array
    {
        $kind = (string) ($meta['kind'] ?? '');

        $base = [
            'key' => $key,
            'label' => $meta['label'] ?? $key,
            'description' => $meta['description'] ?? '',
            'module' => $meta['module'] ?? null,
            'kind' => $kind,
        ];

        return match ($kind) {
            'readonly_env' => $base + [
                'status' => $this->readonlyEnvConfigured($key) ? 'configured' : 'not_configured',
                'env' => $meta['env'] ?? null,
            ],
            'oauth_stub' => $base + [
                'status' => 'not_configured',
            ],
            'crud_existing' => $base + $this->crudExistingSummary($meta, $tenantId),
            'credential' => $base + $this->credentialSummary($meta, $row),
            default => $base + ['status' => 'unknown'],
        };
    }

    /**
     * Whether a `readonly_env` provider is configured — read EXACTLY the way
     * `App\Http\Controllers\Api\TaskManagement\SessionController::integrations()`
     * already reads each of these three, so this screen and that endpoint can never
     * disagree about the same fact. Deliberately not generalised into config data:
     * the three genuinely use different lookup shapes (gemini falls back from a
     * config path to an env var; n8n and fcm each read one differently-named
     * source), and forcing them into one generic pattern would be an abstraction
     * for three special cases, each of which already has exactly one real caller.
     */
    private function readonlyEnvConfigured(string $key): bool
    {
        return match ($key) {
            'gemini' => (string) config('gemini.api_key', env('GEMINI_API_KEY', '')) !== '',
            'n8n' => (string) config('services.n8n.task_webhook', '') !== '',
            'fcm' => (string) env('FCM_SERVER_KEY', '') !== '',
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private function crudExistingSummary(array $meta, int $tenantId): array
    {
        if (! Schema::hasTable('lms_integrations')) {
            return ['status' => 'unknown', 'screen' => $meta['screen'] ?? null, 'connected_count' => null];
        }

        // `whereNull('deleted_at')` matters: LmsPartnerController soft-deletes this
        // table manually (a `deleted_at` column, checked by hand — not Eloquent
        // SoftDeletes), and skipping it here would count a removed integration as
        // still connected.
        $connected = DB::table('lms_integrations')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 'connected')
            ->whereNull('deleted_at')
            ->count();

        $total = DB::table('lms_integrations')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->count();

        return [
            'status' => $connected > 0 ? 'configured' : 'not_configured',
            'screen' => $meta['screen'] ?? null,
            'connected_count' => $connected,
            'total_count' => $total,
        ];
    }

    /** @return array<string, mixed> */
    private function credentialSummary(array $meta, ?object $row): array
    {
        $config = $row === null ? [] : $this->decrypt($row->config);

        $fields = array_map(function (array $field) use ($config) {
            $fieldKey = (string) $field['key'];
            $isPassword = ($field['type'] ?? null) === 'password';
            $value = $config[$fieldKey] ?? null;

            return [
                'key' => $fieldKey,
                'label' => $field['label'] ?? $fieldKey,
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
                'options' => $field['options'] ?? null,
                // NEVER the real value for a password field — only whether one is
                // saved. Every other field's current value is returned so the form
                // can show what is already configured without asking again.
                'value' => $isPassword ? null : $value,
                'has_value' => $isPassword ? ($value !== null && $value !== '') : null,
            ];
        }, $meta['fields'] ?? []);

        return [
            'status' => $row->status ?? 'not_configured',
            'fields' => $fields,
            'last_tested_at' => $row->last_tested_at ?? null,
            'last_test_message' => $row->last_test_message ?? null,
            'updated_at' => $row->updated_at ?? null,
            'updated_by' => $row->updated_by ?? null,
        ];
    }

    private function actor(\App\Services\Ai\AiRequestScope $scope): string
    {
        $name = DB::table('tbluser')->where('id', $scope->userId)->value('first_name');

        return trim(((string) $name) . ' (' . $scope->userId . ')');
    }
}
