<?php

namespace Tests\Security\DirectCallers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SECONDARY GUARD (source text, not behaviour; the behavioural proof is GroupYSitesTest and GroupYAjaxTest): none of the lookup sites changed in A-1c
 * Group Y calls Sanctum's plain `PersonalAccessToken::findToken(` any more, and each file calls the resolver exactly as many times as sites were swapped.
 * Comments are stripped with the PHP tokenizer first, so an explanatory comment cannot satisfy or break the check.
 *
 * Deliberately left on the plain lookup (documented, not changed): AJAXController::searchSkill (redundant behind the `auth` route group, never reads the
 * token's owner).
 */
class GroupYSourceGuardTest extends TestCase
{
    /** file (relative to app/Http/Controllers) => [resolver calls expected, plain Sanctum calls still expected] */
    public static function files(): array
    {
        return [
            'AJAXController' => ['AJAXController.php', 4, 1],
            'GammaApiController' => ['Api/GammaApiController.php', 6, 0],
            'UserImportController' => ['Api/UserImportController.php', 1, 0],
            'UserProfileController' => ['Api/UserProfileController.php', 1, 0],
            'IndustryController' => ['Api/IndustryController.php', 2, 0],
            'jobrolecontroller' => ['Api/jobrolecontroller.php', 3, 0],
            'skillcontroller' => ['Api/skillcontroller.php', 2, 0],
            'UserJourneyLogController' => ['Api/UserJourneyLogController.php', 1, 0],
            'jobroleskillcontroller' => ['libraries/jobroleskillcontroller.php', 1, 0],
            'buildwithAIController' => ['build_with_AI/buildwithAIController.php', 2, 0],
        ];
    }

    private static function code(string $relative): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/' . $relative);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    #[DataProvider('files')]
    public function test_the_changed_sites_use_the_resolver_and_no_plain_lookup_remains(string $relative, int $resolverCalls, int $plainCalls): void
    {
        $code = self::code($relative);

        $this->assertSame($plainCalls, preg_match_all('/PersonalAccessToken::findToken\(/', $code), $relative . ': plain Sanctum lookups left');
        $this->assertSame($resolverCalls, preg_match_all('/\\\\App\\\\Support\\\\PersonalAccessTokenResolver::findToken\(/', $code), $relative . ': resolver lookups');
        $this->assertStringNotContainsString('AppSupportPersonalAccessTokenResolver', $code, $relative . ': a class name with its namespace separators lost would fail at runtime, not at lint');
    }
}
