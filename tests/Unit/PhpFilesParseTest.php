<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every PHP file the application loads must at least parse. A merge once left a half-deleted block in DealController (no `php -l`
 * run) and a stray line in SkillMatrixController, so whole controllers failed to load (defects D1 and D21, found 2026-11-02).
 * This reads each file with PHP's own parser (token_get_all with TOKEN_PARSE); it takes about a second for roughly 2,000 files.
 */
class PhpFilesParseTest extends TestCase
{
    #[DataProvider('directories')]
    public function test_every_php_file_parses(string $directory): void
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $directory;
        $this->assertDirectoryExists($root);

        $broken = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            try {
                token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $broken[] = substr(str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $file->getPathname()), 0, 200) . ':' . $e->getLine() . ' ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken, "PHP files that do not parse in {$directory}/");
    }

    public static function directories(): array
    {
        return [['app'], ['routes'], ['config'], ['database/migrations']];
    }
}
