<?php

namespace Tests\Unit\Intelligence\Adversarial;

use Tests\Intelligence\IntelligenceTestCase;

/**
 * 5. FORBIDDEN-DATA STATIC SCAN.
 *
 * Reads the CODE (comments are stripped with the PHP tokenizer, so a docblock saying "never select an e-mail" does not trip it) of every file under
 * app/Domain/Intelligence and of the HTTP layer of Intelligence, and asserts it never names payroll, performance or personal-data tables/columns, never calls a
 * model or an HTTP client, and never has a side effect (cache, queue, mail, event, write, schema change).
 *
 * Allow-lists are explicit and each entry says why.
 */
class ForbiddenDataStaticScanTest extends IntelligenceTestCase
{
    /** Files that legitimately handle an AUTH token (they authenticate the caller; they never read or write token data of anybody else). */
    private const TOKEN_ALLOWED = [
        'app/Http/Middleware/IntelligenceIdentity.php',
        'app/Http/Middleware/IntelligenceTokenAuthenticator.php',
        'app/Http/Middleware/SanctumIntelligenceTokenAuthenticator.php',
    ];

    /**
     * The AI usage quota's limit column is literally named `token_limit` (an LLM token budget, NOT an authentication token). Exactly that identifier, in exactly this
     * file, is removed before the word-token scan below; any other use of the word in the file still fails the scan.
     */
    private const TOKEN_COLUMN_ALLOWED = ['app/Domain/Intelligence/Detectors/Platform/AiUsageDetector.php' => 'token_limit'];

    /** The one place the domain layer may read configuration (the Signals fallback flag): a factory, never a detector. */
    private const CONFIG_ALLOWED = ['app/Domain/Intelligence/Detectors/Commercial/CommercialAccess.php'];

    /** The places a log line is written: failure isolation in the service and in the HR and platform base classes, class names only (asserted below). */
    private const LOG_ALLOWED = [
        'app/Domain/Intelligence/InsightService.php', 'app/Domain/Intelligence/Detectors/Hr/AbstractHrDetector.php', 'app/Domain/Intelligence/Detectors/Platform/PlatformDetector.php',
    ];

    /** The one place "now" may be read: the clock every detector is handed. */
    private const CLOCK_ALLOWED = ['app/Domain/Intelligence/Support/DetectionClock.php'];

    /** Fails with the matching text only, never with the whole file. */
    private function assertNoMatch(string $pattern, string $code, string $message): void
    {
        $this->assertSame(0, preg_match($pattern, $code, $m), $message . (isset($m[0]) ? ' [matched: ' . trim($m[0]) . ']' : ''));
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return array<string, string> relative path => code with comments and whitespace removed */
    private function code(array $dirsOrFiles, bool $stripStrings = false): array
    {
        $files = [];
        foreach ($dirsOrFiles as $path) {
            $abs = $this->root() . '/' . $path;
            if (is_dir($abs)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        $files[] = $f->getPathname();
                    }
                }
            } elseif (is_file($abs)) {
                $files[] = $abs;
            }
        }
        $out = [];
        foreach ($files as $file) {
            $code = '';
            foreach (token_get_all(file_get_contents($file)) as $t) {
                if (is_array($t)) {
                    if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                        continue;
                    }
                    $code .= ($stripStrings && $t[0] === T_CONSTANT_ENCAPSED_STRING ? '""' : $t[1]) . ' ';
                } else {
                    $code .= $t . ' ';
                }
            }
            $out[str_replace('\\', '/', substr($file, strlen($this->root()) + 1))] = $code;
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> `$stripStrings`: string literals blanked, for patterns that look for CALLS (a sentence containing "time (" is not a call). */
    private function domain(bool $stripStrings = false): array
    {
        return $this->code(['app/Domain/Intelligence'], $stripStrings);
    }

    /** @return array<string, string> */
    private function http(bool $stripStrings = false): array
    {
        return $this->code(['app/Http/Controllers/Api/Intelligence', 'app/Http/Middleware/IntelligenceIdentity.php', 'app/Http/Middleware/IntelligenceTokenAuthenticator.php',
            'app/Http/Middleware/SanctumIntelligenceTokenAuthenticator.php', 'routes/intelligence.php', 'config/intelligence.php'], $stripStrings);
    }

    public function test_the_scan_really_sees_the_code(): void
    {
        $this->assertGreaterThanOrEqual(30, count($this->domain()), 'the domain layer should have contracts, support, 23 detectors and the service');
        $this->assertArrayHasKey('app/Domain/Intelligence/InsightService.php', $this->domain());
        $this->assertArrayHasKey('app/Http/Controllers/Api/Intelligence/IntelligenceController.php', $this->http());
        // The tokenizer drops comments: the word "payroll" appears in a docblock of Insight/Evidence, but never in code.
        $this->assertStringContainsString('payroll', file_get_contents($this->root() . '/app/Domain/Intelligence/Evidence.php') . file_get_contents($this->root() . '/app/Domain/Intelligence/InsightDetector.php') . file_get_contents($this->root() . '/app/Domain/Intelligence/DetectorRegistry.php'));
    }

    public function test_no_payroll_performance_or_personal_data_is_named_in_code(): void
    {
        $patterns = [
            'payroll' => '/payroll/i', 'salary' => '/salar(y|ies)/i', 'employee_monthly_salary_data' => '/employee_monthly_salary_data/i',
            'employee_salary_structures' => '/employee_salary_structures/i', 'hrms_salary_payments' => '/hrms_salary_payments/i', 'performance tables' => '/s_performance_/i',
            'performance reviews' => '/performance_(review|goal|cycle|appraisal|rating)/i', 'plain_password' => '/plain_password|password/i', 'aadhaar' => '/aadhaa?r/i',
            'pan number' => '/pan_?(no|number)/i', 'bank' => '/(?<![a-z])bank/i', 'otp' => '/(?<![a-z])otp(?![a-z])/i', 'email' => '/e_?mail/i',
            'phone/mobile' => '/(?<![a-z])(phone|mobile)(?![a-z])/i', 'ifsc/ssn/card' => '/(?<![a-z])(ifsc|ssn|cvv|iban)(?![a-z])/i',
        ];
        foreach (array_merge($this->domain(), $this->http()) as $file => $code) {
            foreach ($patterns as $what => $pattern) {
                $this->assertNoMatch($pattern, $code, "$file names forbidden data ($what)");
            }
        }
    }

    public function test_the_platform_detectors_never_name_a_secret_credential_free_text_or_content_column(): void
    {
        $platform = $this->code(['app/Domain/Intelligence/Detectors/Platform']);
        $this->assertGreaterThanOrEqual(8, count($platform), 'the platform detectors and their two base files');
        $forbidden = [
            'credential blob' => "/['\"]config['\"]/", 'test message' => '/last_test_message/', 'audit names' => '/(created_by|updated_by|actor_id|actor_label)/', 'run output' => '/output_head|[\'"]host[\'"]/',
            'event error/payload' => '/last_error|[\'"]payload[\'"]|[\'"]metadata[\'"]/', 'AI call content' => '/[\'"](model|related_type|related_id|user_id)[\'"]/',
            'agent content' => '/system_prompt|error_message|[\'"](input|output|endpoint_url|endpoint_headers|tools)[\'"]/', 'keys and acknowledgements' => '/ai_api_keys|api_key|ai_policy_acknowledgements/',
            'select all' => '/->select\s*\(\s*[\'"]\*[\'"]|get\s*\(\s*\[\s*[\'"]\*[\'"]/',
        ];
        foreach ($platform as $file => $code) {
            foreach ($forbidden as $what => $pattern) {
                $this->assertNoMatch($pattern, $code, "$file names a forbidden column ($what)");
            }
        }
        // `error` is a legitimate run STATUS value for agents, but it is the error-TEXT column of an AI usage call: that one file must never name it.
        $this->assertNoMatch('/[\'"]error[\'"]/', $platform['app/Domain/Intelligence/Detectors/Platform/AiUsageDetector.php'], 'the AI usage detector names the error-text column');
    }
    public function test_the_word_token_appears_only_in_the_three_authentication_files(): void
    {
        foreach (array_merge($this->domain(), $this->http()) as $file => $code) {
            if (in_array($file, self::TOKEN_ALLOWED, true)) {
                continue;
            }
            if (isset(self::TOKEN_COLUMN_ALLOWED[$file])) {
                $this->assertStringContainsString(self::TOKEN_COLUMN_ALLOWED[$file], $code, "$file is allow-listed for its quota column but no longer uses it: tighten the list");
                $code = str_replace(self::TOKEN_COLUMN_ALLOWED[$file], '', $code);
            }
            $this->assertNoMatch('/(?<![a-z])token(?![a-z])/i', $code, "$file mentions a token outside the authentication files");
        }
        foreach (self::TOKEN_ALLOWED as $allowed) {
            $this->assertArrayHasKey($allowed, $this->http(), "$allowed is allow-listed but does not exist: tighten the list");
        }
    }

    public function test_no_model_http_client_or_external_call_is_used(): void
    {
        $patterns = ['Http facade' => '/\bHttp\s*::/', 'Guzzle' => '/guzzle/i', 'curl' => '/\bcurl_\w+/i', 'AiModelClient' => '/AiModelClient/i', 'DeepSeek' => '/deepseek/i', 'Gemini' => '/gemini/i',
            'OpenAI' => '/openai/i', 'Anthropic/Claude' => '/anthropic|claude/i', 'AI usage meter' => '/AiUsageMeter|StructuredAi|AiConfigurationResolver/i', 'raw network' => '/file_get_contents\s*\(\s*[\'"]http|fsockopen|stream_socket_client|SoapClient/i',
            'process execution' => '/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/'];
        foreach (array_merge($this->domain(), $this->http()) as $file => $code) {
            foreach ($patterns as $what => $pattern) {
                $this->assertNoMatch($pattern, $code, "$file uses $what");
            }
        }
    }

    public function test_no_side_effect_cache_queue_mail_event_write_or_schema_change_exists(): void
    {
        $patterns = [
            'Cache' => '/\bCache\s*::|cache\s*\(/', 'Queue' => '/\bQueue\s*::|ShouldQueue/', 'dispatch' => '/\bdispatch(Sync|Now)?\s*\(/', 'Bus' => '/\bBus\s*::/', 'Mail' => '/\bMail\s*::|Mailable/',
            'Notification' => '/\bNotification\s*::|->notify\s*\(/', 'event()' => '/\bevent\s*\(/', 'Event facade' => '/\bEvent\s*::/', 'Redis' => '/\bRedis\s*::/', 'Storage' => '/\bStorage\s*::|file_put_contents|fopen\s*\(|unlink\s*\(/',
            'session write' => '/\bsession\s*\(\s*\[|Session\s*::\s*put/', 'insert' => '/->insert(GetId|OrIgnore|Using)?\s*\(/', 'update' => '/->update(OrInsert)?\s*\(/', 'delete' => '/->(delete|forceDelete|truncate)\s*\(/',
            'upsert' => '/->upsert\s*\(/', 'increment' => '/->(increment|decrement)\s*\(/', 'raw statements' => '/\bDB\s*::\s*(statement|insert|update|delete|unprepared|affectingStatement)|->statement\s*\(|->unprepared\s*\(|->affectingStatement\s*\(/',
            'schema change' => '/\bSchema\s*::|Blueprint/', 'transaction' => '/->transaction\s*\(|beginTransaction|DB\s*::\s*transaction/', 'DB facade (other than DB::connection())' => '/\bDB\s*::(?!\s*connection\s*\()/', 'model save' => '/->save\s*\(|::create\s*\(|->create\s*\(|->firstOrCreate|->updateOrCreate/',
        ];
        foreach (array_merge($this->domain(true), $this->http(true)) as $file => $code) {
            foreach ($patterns as $what => $pattern) {
                $this->assertNoMatch($pattern, $code, "$file has a side effect ($what)");
            }
        }
    }

    public function test_configuration_logging_and_the_clock_are_reached_only_where_allowed(): void
    {
        foreach ($this->domain(true) as $file => $code) {
            if (! in_array($file, self::CONFIG_ALLOWED, true)) {
                $this->assertNoMatch('/\bconfig\s*\(|\benv\s*\(/', $code, "$file reads configuration outside the allowed factory");
            }
            if (! in_array($file, self::LOG_ALLOWED, true)) {
                $this->assertNoMatch('/\bLog\s*::|\blogger\s*\(|error_log\s*\(/', $code, "$file logs outside the service");
            }
            if (! in_array($file, self::CLOCK_ALLOWED, true)) {
                // The service builds the PRODUCTION clock once, by asking DetectionClock for it, when none was injected: the single composition point.
                $uses = substr_count($code, 'DetectionClock :: now ( )');
                $this->assertSame($file === 'app/Domain/Intelligence/InsightService.php' ? 1 : 0, $uses, "$file builds a production clock outside the service");
                $code = str_replace('DetectionClock :: now ( )', '', $code);
                $this->assertNoMatch('/\bnow\s*\(|Carbon(Immutable)?\s*::\s*(now|today|createFromTimestamp|createFromTimestampUTC)\b|parse\s*\(\s*[\'"](now|today|tomorrow|yesterday)|\btime\s*\(|\bdate\s*\(|\bstrtotime\s*\(|\bmicrotime\s*\(|new\s+\\\\?(DateTime|DateTimeImmutable)\b/', $code, "$file reads the real clock instead of the injected DetectionClock");
            }
        }
        foreach (array_merge(self::CONFIG_ALLOWED, self::LOG_ALLOWED, self::CLOCK_ALLOWED) as $allowed) {
            $this->assertFileExists($this->root() . '/' . $allowed, "$allowed is allow-listed but does not exist");
        }
    }

    public function test_the_only_log_calls_carry_class_names_not_messages(): void
    {
        foreach (self::LOG_ALLOWED as $file) {
            $code = $this->domain()[$file];

            $this->assertSame(1, preg_match_all('/\bLog\s*::\s*warning\s*\(/', $code), "$file: exactly one log call");
            $this->assertNoMatch('/getMessage|getTraceAsString|getTrace\s*\(|getFile|getLine|__toString/', $code, "$file: the exception text must never reach the log or the response");
            // The log context names the exception's CLASS (`$e::class`) and nothing else about it.
            $this->assertSame(1, preg_match('/\'exception\'\s*=>\s*\$e\s*::\s*class/', $code), "$file: the log context must be the exception class");
            $this->assertSame(0, preg_match('/Log\s*::\s*warning\s*\([^;]*\$e\s*[,)]/', preg_replace('/\$e\s*::\s*class/', '', $code)), "$file: the exception object itself must not be passed to the log");
        }
    }

    public function test_no_detector_or_file_is_named_after_payroll_or_performance(): void
    {
        foreach (array_keys(array_merge($this->domain(), $this->http())) as $file) {
            $this->assertDoesNotMatchRegularExpression('/payroll|salary|performance|appraisal/i', basename($file), "$file");
        }
        $ids = array_map(static fn ($d) => $d->id(), \App\Domain\Intelligence\DetectorRegistry::forConnection($this->db(), \App\Domain\Intelligence\Support\Thresholds::defaults()));
        foreach ($ids as $id) {
            $this->assertDoesNotMatchRegularExpression('/payroll|salary|performance|appraisal/i', $id);
        }
        $this->assertCount(23, array_unique($ids));
    }
}
