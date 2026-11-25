<?php

namespace Tests\Security\DirectCallers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SECONDARY GUARD (text-level, not behavioural): the behaviour is proven by the other GroupX tests, which run the real classes. This only stops a future
 * edit from quietly putting the plain Sanctum lookup back into one of the files A-1c changed. It uses the PHP tokenizer, so comments and strings do not
 * count, and it does not boot the application or touch any database.
 */
class GroupXSourceGuardTest extends TestCase
{
    /** The thirteen files whose direct lookups were replaced (A-1c, group X). */
    public const FILES = [
        'app/Http/Middleware/RequireHritRole.php',
        'app/Http/Middleware/RequireMenuRight.php',
        'app/Http/Middleware/RequirePlatformOwner.php',
        'app/Http/Middleware/RequirePlatformRight.php',
        'app/Http/Middleware/RequireSubjectAuthority.php',
        'app/Http/Middleware/TaskPermissionMiddleware.php',
        'app/Http/Controllers/Api/TaskManagement/Concerns/ResolvesTaskContext.php',
        'app/Http/Controllers/Api/TaskManagement/DependencyController.php',
        'app/Http/Controllers/Api/TaskManagement/MyTasksController.php',
        'app/Http/Controllers/Api/TaskManagement/ProjectController.php',
        'app/Http/Controllers/Api/TaskManagement/TaskListController.php',
        'app/Http/Controllers/Api/TaskManagement/WorkspaceController.php',
        'app/Http/Controllers/Payroll/PayrollController.php',
    ];

    /** Lookups expected per file (the sites that were swapped). */
    public const EXPECTED_SITES = ['app/Http/Controllers/Payroll/PayrollController.php' => 6];

    /** @return array<string, array{0: string}> */
    public static function files(): array
    {
        $rows = [];
        foreach (self::FILES as $file) {
            $rows[$file] = [$file];
        }

        return $rows;
    }

    /** @return list<string> the code tokens of a file with comments and whitespace removed, as plain strings */
    private function codeTokens(string $relative): array
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);
        $out = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $out[] = $token[1];
            } else {
                $out[] = $token;
            }
        }

        return $out;
    }

    #[DataProvider('files')]
    public function test_no_changed_file_calls_the_plain_sanctum_lookup_any_more(string $relative): void
    {
        $tokens = $this->codeTokens($relative);
        $direct = 0;
        $resolver = 0;
        foreach ($tokens as $i => $text) {
            $isFindToken = $text === 'findToken' && ($tokens[$i - 1] ?? '') === '::' && ($tokens[$i + 1] ?? '') === '(';
            if (! $isFindToken) {
                continue;
            }
            $owner = $tokens[$i - 2] ?? '';
            if (str_ends_with($owner, 'PersonalAccessTokenResolver')) {
                $resolver++;
            } else {
                $direct++;
            }
        }

        $this->assertSame(0, $direct, "{$relative} must not call a plain `::findToken(` (found {$direct}); use App\\Support\\PersonalAccessTokenResolver");
        $this->assertSame(self::EXPECTED_SITES[$relative] ?? 1, $resolver, "{$relative}: the resolver is used exactly where the plain lookup was");
    }

    #[DataProvider('files')]
    public function test_no_changed_file_still_imports_the_sanctum_token_model(string $relative): void
    {
        $this->assertStringNotContainsString('use Laravel\\Sanctum\\PersonalAccessToken;', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative));
    }
}
