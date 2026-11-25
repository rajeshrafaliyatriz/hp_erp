<?php

namespace Tests\Security\DirectCallers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SECONDARY GUARD (text, not behaviour; the behaviour is in GroupZSitesTest): the swapped sites really call the strict resolver, in code (comments stripped), with
 * the leading backslash intact. A `sed` slip once produced `AppSupportPersonalAccessTokenResolver::findToken(` (no backslashes): it lints, then fails at runtime.
 *
 * Sites deliberately left on the plain lookup (outside Group Z): chapterController::index, TalentOfferController::store. Their count is not asserted here, so a
 * later swap does not break this test.
 */
class GroupZSourceGuardTest extends TestCase
{
    /** file => the number of swapped sites */
    public static function swappedProvider(): array
    {
        return [
            'SkillDashboardController' => ['app/Http/Controllers/dashboards/SkillDashboardController.php', 1],
            'dashboardController' => ['app/Http/Controllers/dashboardController.php', 1],
            'IdmsDocumentController' => ['app/Http/Controllers/Idms/IdmsDocumentController.php', 1],
            'chapterController' => ['app/Http/Controllers/lms/chapterController.php', 3],
            'TalentOfferController' => ['app/Http/Controllers/talent/TalentOfferController.php', 2],
        ];
    }

    #[DataProvider('swappedProvider')]
    public function test_the_changed_sites_call_the_resolver_with_its_full_name(string $file, int $sites): void
    {
        $code = $this->codeOf($file);

        $this->assertSame($sites, substr_count($code, '\App\Support\PersonalAccessTokenResolver::findToken('), "$file: swapped sites");
        $this->assertStringNotContainsString('AppSupportPersonalAccessTokenResolver', $code, "$file: the backslashes must survive the edit");
    }

    public function test_group_z_files_that_were_fully_swapped_have_no_plain_lookup_left(): void
    {
        foreach (['dashboards/SkillDashboardController.php', 'dashboardController.php', 'Idms/IdmsDocumentController.php'] as $file) {
            $this->assertStringNotContainsString('PersonalAccessToken::findToken(', $this->codeOf('app/Http/Controllers/' . $file), $file);
        }
    }

    /** PHP source with comments removed. */
    private function codeOf(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }
}
