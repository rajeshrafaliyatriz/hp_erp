<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The files of the delete, move and picker work (S15 follow-up, 24 Nov 2026) keep three promises that no behaviour test can see from outside, so they are read as text:
 *
 *   1. No tenant 0 and no "shared" assumption (D-1): none of them names `ProductMaster::SHARED`, a `[$tenant, 0]` list or the old `[tenant, SHARED]` pattern. A row is the
 *      organisation's own or it is not visible.
 *   2. History goes through the recorder (D-8): none of them writes `g2g_audit_log` or `g2g_event` itself; the lines are handed to App\Services\Events\EventRecorder.
 *   3. MariaDB 10.1 safe: no JSON function, no window function, no common table expression, no SKIP LOCKED (the live host is 10.1.48). MariaDb101SafetyTest scans the
 *      whole directory too; this file says the same about these files by name.
 * Comments are stripped before scanning, so a sentence that names a forbidden thing is not a use of it.
 */
class ProductRemovalFilesScanTest extends TestCase
{
    private const FILES = [
        'app/Domain/ProductMaster/ProductRemoval.php',
        'app/Domain/ProductMaster/ProductMover.php',
        'app/Domain/ProductMaster/ProductChangeEvents.php',
        'app/Domain/ProductMaster/ProductRemovalRefused.php',
        'app/Domain/ProductMaster/ProductLineChoices.php',
        'app/Domain/ProductMaster/ProductPicker.php',
        'app/Http/Controllers/Api/ProductMaster/ProductRemovalController.php',
        'app/Http/Controllers/Api/ProductMaster/ProductPickerController.php',
    ];

    /** @return array<string, string> file => its code with every comment removed */
    private function code(): array
    {
        $out = [];
        foreach (self::FILES as $file) {
            $path = dirname(__DIR__, 2) . '/' . $file;
            $this->assertFileExists($path);
            $code = '';
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token)) {
                    if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $code .= $token[1];
                    }
                } else {
                    $code .= $token;
                }
            }
            $out[$file] = $code;
        }

        return $out;
    }

    public function test_no_file_reads_a_shared_tenant_or_names_the_old_sharing(): void
    {
        foreach ($this->code() as $file => $code) {
            $this->assertStringNotContainsString('ProductMaster::SHARED', $code, "{$file}: a Product is the organisation's own (D-1)");
            $this->assertDoesNotMatchRegularExpression('/\[\s*\$[\w>\-\[\]\']*(tenant|Tenant)[\w>\-\[\]\']*\s*,\s*(0|self::SHARED|ProductMaster::SHARED)\s*\]/', $code, "{$file}: no [tenant, 0] list");
            $this->assertDoesNotMatchRegularExpression('/sub_institute_id\'\s*,\s*(0|\[\s*[^\]]*,\s*0\s*\])\s*\)/', $code, "{$file}: no tenant 0 lookup");
        }
    }

    public function test_no_file_writes_the_audit_log_or_the_event_store_itself(): void
    {
        foreach ($this->code() as $file => $code) {
            $this->assertStringNotContainsString('g2g_audit_log', $code, "{$file}: history goes through the EventRecorder");
            $this->assertDoesNotMatchRegularExpression("/table\\(\\s*'g2g_event'\\s*\\)/", $code, "{$file}: only the EventRecorder writes g2g_event");
        }
        $events = $this->code()['app/Domain/ProductMaster/ProductChangeEvents.php'];
        $this->assertStringContainsString('app(EventRecorder::class)->record(', $events, 'the one place the lines are written is the recorder');
    }

    public function test_no_file_uses_what_mariadb_10_1_cannot_run(): void
    {
        $rules = [
            'a JSON function' => '/\bJSON_(EXTRACT|UNQUOTE|CONTAINS|SEARCH|OBJECT|ARRAY|SET|VALUE|LENGTH|TYPE|VALID|KEYS|MERGE)\b/i',
            'a JSON query helper' => '/->\s*(whereJsonContains|whereJsonLength|whereJsonDoesntContain|orWhereJsonContains)\s*\(/',
            'SKIP LOCKED / NOWAIT' => '/\b(SKIP\s+LOCKED|NOWAIT)\b|->\s*skipLocked\s*\(/i',
            'a window function' => '/\bOVER\s*\(\s*(PARTITION|ORDER)\b/i',
            'a common table expression' => '/\bWITH\s+(RECURSIVE\s+)?[a-z_][a-z0-9_]*\s+AS\s*\(/i',
            'a bound parameter in SHOW ... LIKE ?' => '/\bSHOW\s+(FULL\s+)?(COLUMNS|INDEX|INDEXES|KEYS|TABLES)\b[^;"\']*\bLIKE\s*\?/i',
        ];
        foreach ($this->code() as $file => $code) {
            foreach ($rules as $what => $regex) {
                $this->assertDoesNotMatchRegularExpression($regex, $code, "{$file}: {$what} (the live host is MariaDB 10.1)");
            }
        }
    }
}
