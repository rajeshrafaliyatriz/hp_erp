<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No PHP file of the application may start with a UTF-8 byte-order mark (the three bytes EF BB BF). They sit before `<?php`, so PHP prints them as
 * text every time the file is loaded, and a route file is loaded on every request: then EVERY response (a JSON answer, a page, a downloaded file)
 * starts with them. JSON clients tolerate that and nobody notices, but a downloaded file is longer by three bytes and its hash no longer matches:
 * the simulations' 3D models were refused as "not the file the lesson expects" for exactly this reason (routes/api.php, 2026-10-28).
 *
 * Editors on Windows add the mark when asked to save as "UTF-8"; the one to choose is "UTF-8" without a BOM (in VS Code: Save with Encoding, UTF-8).
 */
class NoByteOrderMarkTest extends TestCase
{
    /** The folders whose PHP files are loaded by the application (the third-party code under public/ is not). */
    private const FOLDERS = ['app', 'bootstrap', 'config', 'database', 'resources/views', 'routes'];

    public function test_no_php_file_of_the_application_starts_with_a_byte_order_mark(): void
    {
        $root = dirname(__DIR__, 2);
        $found = $this->withByteOrderMark(array_map(fn (string $folder) => $root . '/' . $folder, self::FOLDERS));

        $this->assertSame([], $found, "These files start with a byte-order mark, which puts three stray bytes in front of every response:\n  " . implode("\n  ", $found));
    }

    public function test_the_check_does_see_a_file_that_starts_with_one(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/bom-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/bad.php', "\xEF\xBB\xBF<?php\nreturn 1;\n");
        file_put_contents($dir . '/good.php', "<?php\nreturn 1;\n");

        try {
            $this->assertSame([$dir . '/bad.php'], $this->withByteOrderMark([$dir]));
        } finally {
            @unlink($dir . '/bad.php');
            @unlink($dir . '/good.php');
            @rmdir($dir);
        }
    }

    /**
     * @param  string[]  $folders
     * @return string[] the PHP files in them, at any depth, whose first three bytes are a byte-order mark
     */
    private function withByteOrderMark(array $folders): array
    {
        $found = [];
        foreach ($folders as $folder) {
            if (! is_dir($folder)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && file_get_contents($file->getPathname(), false, null, 0, 3) === "\xEF\xBB\xBF") {
                    $found[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        sort($found);

        return $found;
    }
}
