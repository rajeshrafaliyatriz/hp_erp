<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nothing the back end writes may point at the retired Signal Review screen. It was merged into Company Opportunities on 13 Nov 2026 (docs/agentic-crm/DECISIONS.md,
 * entry 2026-11-13; track S15-P1B): a signal a person is shown a link to now opens on `/signals?status=all&q=<title>`. The two old addresses, `/signal-review` and
 * `/module/crm/signal-review`, live on ONLY as redirect pages in the front end, because an Ask answer or an agent run stored before the merge still carries them.
 *
 * This reads every file of the code that could build such a link (app, config, routes, database/seeders, resources) and fails on any mention of either address.
 * Migrations are history and tests are scanned by their own assertions, so neither is read here: a migration names the retired menu ROW by its access_link, on purpose.
 *
 * The one mention that stays is the menu row's identity in the grant command (row 506 keeps `status 1`, because a Role & Permissions save deletes the rights of any row that is
 * not `status 1`; the sidebar hides it in code). The allowance is that ONE line of that ONE file, not the file: a link built in the same file is still caught.
 *
 * Plain PHPUnit, no application and no database. The detector is itself tested on lines that do and do not name the address, so a pass means something.
 */
class SignalReviewAddressGoneTest extends TestCase
{
    /** The folders whose files can build a link a person is shown. */
    private const FOLDERS = ['app', 'config', 'routes', 'database/seeders', 'resources'];

    private const EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'json', 'md', 'txt', 'yml', 'yaml'];

    /**
     * The retired address as a PATH: `/signal-review` that does not continue into a longer word or file name (`/signal-review-list`) and is not part of a folder path
     * (`services/signal-review/`, `../signal-review/`), or `/module/crm/signal-review`, which ends in a word character and so needs its own way in.
     */
    private const ADDRESS = '~(?:(?<![\w.\-])|(?<=/module/crm))/signal-review(?![\w\-])~';

    /** The lines that may name it: relative path => [a pattern the whole line must match, the reason]. */
    private const ALLOWED = [
        'app/Console/Commands/SignalsGrantReviewCommand.php' => [
            "~^\\s*private const MENU_LINK = '/signal-review';\\s*$~",
            'the retired screen\'s menu row keeps status 1 in the database and this command finds it by its access_link (never by id)',
        ],
    ];

    public function test_nothing_the_back_end_writes_points_at_the_retired_screen(): void
    {
        $found = [];
        foreach ($this->files($this->root(), self::FOLDERS) as $relative => $path) {
            $found = array_merge($found, $this->violations($relative, (string) file_get_contents($path)));
        }

        $this->assertSame([], $found, "These lines name the retired Signal Review address. A signal now opens on /signals?status=all&q=<title>:\n  " . implode("\n  ", $found));
    }

    public function test_the_scan_reads_the_files_that_used_to_build_the_link_and_enough_of_the_application(): void
    {
        $scanned = array_keys($this->files($this->root(), self::FOLDERS));

        foreach (['app/Domain/Ask/AskSignals.php', 'app/Domain/AgentGateway/OrgAgentContextBuilder.php', 'app/Console/Commands/SignalsGrantReviewCommand.php', 'config/signals.php', 'routes/api.php'] as $expected) {
            $this->assertContains($expected, $scanned, "{$expected} is not scanned");
        }
        foreach (self::FOLDERS as $folder) {
            $this->assertNotEmpty(array_filter($scanned, fn (string $path) => str_starts_with($path, $folder . '/')), "{$folder} was not walked");
        }
        $this->assertGreaterThan(1000, count($scanned), 'a folder was only partly walked (about 1,700 files at the time of writing)');
        $this->assertArrayNotHasKey('tests/Feature/SignalReviewMenuTest.php', array_flip($scanned), 'tests are not scanned: they pin the retired menu row on purpose');
    }

    public function test_the_two_old_addresses_are_seen_and_lookalikes_are_not(): void
    {
        foreach ([
            "\$url = '/signal-review';",
            "\$url = '/module/crm/signal-review?status=all&q=' . rawurlencode(\$title);",
            '"/signal-review?status=all"',
            "\$u = url('/signal-review/');",
            "'/x' . '/module/crm/signal-review'",
        ] as $line) {
            $this->assertSame(1, preg_match(self::ADDRESS, $line), "should be seen: {$line}");
        }

        foreach ([
            "import x from '@/services/signal-review/types';",
            "require '../signal-review/page';",
            "'/signal-review-list'",
            "'/signal-review_x'",
            "'signal-review'",
            "\$url = '/signals?status=all&q=' . rawurlencode(\$title);",
            "'/module/crm/signals'",
            "DB::table('g2g_signal_reviews')",
            "'/module/crm/signal-reviews'",
        ] as $line) {
            $this->assertSame(0, preg_match(self::ADDRESS, $line), "should be ignored: {$line}");
        }
    }

    public function test_the_allowance_is_one_line_of_one_file_not_the_file(): void
    {
        $file = 'app/Console/Commands/SignalsGrantReviewCommand.php';

        $this->assertSame([], $this->violations($file, "    private const MENU_LINK = '/signal-review';\n"), 'the menu row identity line is allowed');
        $this->assertCount(1, $this->violations($file, "    private const MENU_LINK = '/signal-review';\n    \$link = '/signal-review?status=all';\n"), 'a link built in the same file is caught');
        $this->assertCount(1, $this->violations($file, "    private const MENU_LINK = '/module/crm/signal-review';\n"), 'the other spelling is not the allowed line');
        $this->assertCount(1, $this->violations('app/Domain/Ask/AskSignals.php', "    private const MENU_LINK = '/signal-review';\n"), 'the same line in another file is caught');
    }

    public function test_the_check_does_see_a_file_that_names_the_old_address(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/signal-review-gone-' . bin2hex(random_bytes(4));
        mkdir($dir . '/app', 0777, true);
        file_put_contents($dir . '/app/Bad.php', "<?php\nreturn '/module/crm/signal-review?status=all&q=' . rawurlencode('x');\n");
        file_put_contents($dir . '/app/Good.php', "<?php\nreturn '/signals?status=all&q=' . rawurlencode('x');\n");

        try {
            $found = [];
            foreach ($this->files($dir, ['app']) as $relative => $path) {
                $found = array_merge($found, $this->violations($relative, (string) file_get_contents($path)));
            }
            $this->assertCount(1, $found);
            $this->assertStringStartsWith('app/Bad.php:2:', $found[0]);
        } finally {
            @unlink($dir . '/app/Bad.php');
            @unlink($dir . '/app/Good.php');
            @rmdir($dir . '/app');
            @rmdir($dir);
        }
    }

    private function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    /**
     * @param  string[]  $folders  relative to $root
     * @return array<string, string> relative path (forward slashes) => absolute path
     */
    private function files(string $root, array $folders): array
    {
        $files = [];
        foreach ($folders as $folder) {
            if (! is_dir($root . '/' . $folder)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    $absolute = str_replace('\\', '/', $file->getPathname());
                    $files[ltrim(substr($absolute, strlen($root)), '/')] = $absolute;
                }
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<string> "relative/path:line: the line" for each line that names the retired address and is not the one allowed line of its file
     */
    private function violations(string $relative, string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
            if (preg_match(self::ADDRESS, $line) !== 1) {
                continue;
            }
            $allowed = self::ALLOWED[$relative] ?? null;
            if ($allowed !== null && preg_match($allowed[0], $line) === 1) {
                continue;
            }
            $found[] = $relative . ':' . ($index + 1) . ': ' . trim($line);
        }

        return $found;
    }
}
