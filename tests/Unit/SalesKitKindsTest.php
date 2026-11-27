<?php

namespace Tests\Unit;

use App\Domain\ProductMaster\Kit\KitKinds;
use App\Domain\ProductMaster\Kit\KitRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Sales Kit's rules that need no database (App\Domain\ProductMaster\Kit\KitKinds): the kind of a file, what a person may change it to, the extensions the kit takes and what a link may point at.
 * The same rules through the API are in tests/Feature/SalesKitApiTest.php.
 */
class SalesKitKindsTest extends TestCase
{
    public function test_the_kit_takes_the_library_types_and_gif_and_three_video_types(): void
    {
        $kit = KitKinds::extensions();
        $library = explode(',', (string) config('documents.allowed_extensions'));

        $this->assertSame([], array_values(array_diff($library, $kit)), 'everything the library takes, the kit takes');
        $this->assertSame(['gif', 'mp4', 'webm', 'mov'], array_values(array_diff($kit, $library)));
        foreach ($kit as $extension) {
            $this->assertNotNull(KitKinds::forExtension($extension), "{$extension} has a kind");
        }
        $this->assertNull(KitKinds::forExtension('exe'));
    }

    /** @return array<string, array{string, string}> */
    public static function kinds(): array
    {
        return [
            'ppt' => ['ppt', 'presentation'], 'PPTX upper case' => ['PPTX', 'presentation'], 'pdf' => ['pdf', 'document'], 'docx' => ['docx', 'document'], 'xlsx' => ['xlsx', 'document'],
            'csv' => ['csv', 'document'], 'jpeg' => ['jpeg', 'image'], 'gif' => ['gif', 'image'], 'mp4' => ['mp4', 'video'], 'mov' => ['mov', 'video'], 'webm' => ['webm', 'video'],
        ];
    }

    #[DataProvider('kinds')]
    public function test_the_kind_follows_the_extension(string $extension, string $kind): void
    {
        $this->assertSame($kind, KitKinds::forExtension($extension));
        $this->assertSame($kind, KitKinds::choose($extension, null));
        $this->assertSame($kind, KitKinds::choose($extension, ''));
        $this->assertSame($kind, KitKinds::choose($extension, strtoupper($kind)), 'the kind a file already is is always accepted');
    }

    public function test_only_pdf_ppt_and_pptx_may_be_filed_as_either_a_presentation_or_a_document(): void
    {
        foreach (['pdf', 'ppt', 'pptx'] as $extension) {
            $this->assertSame(['presentation', 'document'], KitKinds::allowedFor($extension));
            $this->assertSame('presentation', KitKinds::choose($extension, 'presentation'));
            $this->assertSame('document', KitKinds::choose($extension, 'document'));
            $this->assertSame(KitKinds::forExtension($extension), KitKinds::choose($extension, null));
        }
        foreach (['doc', 'docx', 'xls', 'xlsx', 'txt', 'rtf', 'odt', 'csv', 'jpg', 'png', 'gif', 'mp4', 'webm', 'mov'] as $extension) {
            $own = KitKinds::forExtension($extension);
            $this->assertSame([$own], KitKinds::allowedFor($extension), $extension);
            foreach (array_diff(KitKinds::ALL, [$own]) as $other) {
                try {
                    KitKinds::choose($extension, $other);
                    $this->fail("a .{$extension} file was filed as a {$other}");
                } catch (KitRefused $e) {
                    $this->assertSame(422, $e->status);
                    $this->assertStringContainsString($extension, $e->getMessage());
                }
            }
        }
    }

    public function test_a_kind_that_does_not_exist_is_refused(): void
    {
        $this->expectException(KitRefused::class);
        $this->expectExceptionMessage('Kind must be one of');

        KitKinds::choose('pdf', 'banner');
    }

    public function test_a_library_document_with_no_extension_keeps_its_kind_and_may_switch_between_the_two_text_kinds_only(): void
    {
        $this->assertSame('document', KitKinds::choose('', null));
        $this->assertSame(['presentation', 'document'], KitKinds::allowedFor('', 'document'));
        $this->assertSame(['video'], KitKinds::allowedFor(null, 'video'));
        $this->assertSame('video', KitKinds::choose('', null, 'video'));
        $this->expectException(KitRefused::class);

        KitKinds::choose('', 'image', 'video');
    }

    public function test_the_extension_of_a_file_name_is_lower_case_and_blank_when_there_is_none(): void
    {
        $this->assertSame('pptx', KitKinds::extensionOf('Deck.V2.PPTX'));
        $this->assertSame('', KitKinds::extensionOf('README'));
        $this->assertSame('', KitKinds::extensionOf(''));
    }

    /** @return array<string, array{string}> */
    public static function goodAddresses(): array
    {
        return [
            'https' => ['https://www.youtube.com/watch?v=abc'], 'https with a port and a path' => ['https://example.com:8443/a/b?c=d#e'], 'a page in the app' => ['/signals/portfolio/deck/scholarclone'],
            'the app root' => ['/'], 'upper case scheme' => ['HTTPS://Example.com/x'], 'padded' => ['  https://example.com/x  '],
        ];
    }

    #[DataProvider('goodAddresses')]
    public function test_an_https_address_or_a_page_inside_the_app_is_accepted(string $url): void
    {
        $this->assertNull(KitKinds::urlProblem($url));
    }

    /** @return array<string, array{string}> */
    public static function badAddresses(): array
    {
        return [
            'empty' => [''], 'blank' => ['   '], 'http' => ['http://example.com'], 'javascript' => ['javascript:alert(1)'], 'data' => ['data:text/html;base64,AAAA'], 'ftp' => ['ftp://example.com'],
            'mailto' => ['mailto:a@b.co'], 'protocol relative' => ['//evil.example/x'], 'backslash host' => ['/\\evil.example'], 'backslash' => ['https://example.com\\x'], 'no scheme' => ['example.com/x'],
            'no host' => ['https://'], 'empty host' => ['https:///x'], 'a space' => ['https://example.com/a b'], 'a tab' => ["https://example.com/a\tb"], 'a newline' => ["/deck\nSet-Cookie: x=1"],
            'too long' => ['https://example.com/' . str_repeat('a', 490)],
        ];
    }

    #[DataProvider('badAddresses')]
    public function test_any_other_address_is_refused_with_a_sentence(string $url): void
    {
        $problem = KitKinds::urlProblem($url);

        $this->assertIsString($problem);
        $this->assertNotSame('', $problem);
    }

    // ---- what a file really is (Office files whose sniffed type cannot be trusted) ----

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** A file on disk with the given bytes. */
    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kitkinds');
        file_put_contents($path, $bytes);

        return $this->temp[] = $path;
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kitkinds');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $this->temp[] = $path;
    }

    public function test_the_three_office_families_and_the_type_each_extension_is_stored_with(): void
    {
        foreach (['docx', 'xlsx', 'pptx', 'PPTX'] as $extension) {
            $this->assertSame('ooxml', KitKinds::family($extension));
        }
        foreach (['doc', 'xls', 'ppt'] as $extension) {
            $this->assertSame('ole', KitKinds::family($extension));
        }
        $this->assertSame('odt', KitKinds::family('odt'));
        foreach (['pdf', 'png', 'mp4', 'txt', 'csv', 'rtf', 'exe', ''] as $extension) {
            $this->assertNull(KitKinds::family($extension), "'{$extension}' keeps the sniff-based check");
            $this->assertNull(KitKinds::officeMime($extension));
        }
        $this->assertSame(self::PPTX, KitKinds::officeMime('pptx'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', KitKinds::officeMime('docx'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', KitKinds::officeMime('xlsx'));
        $this->assertSame('application/vnd.ms-powerpoint', KitKinds::officeMime('ppt'));
        $this->assertSame('application/msword', KitKinds::officeMime('doc'));
        $this->assertSame('application/vnd.ms-excel', KitKinds::officeMime('xls'));
        $this->assertSame('application/vnd.oasis.opendocument.text', KitKinds::officeMime('odt'));
    }

    /** @return array<string, array{string}> */
    public static function ooxmlSniffs(): array
    {
        return ['octet-stream' => ['application/octet-stream'], 'zip' => ['application/zip'], 'x-zip-compressed' => ['application/x-zip-compressed'], 'its own type' => [self::PPTX], 'upper case' => ['Application/ZIP']];
    }

    #[DataProvider('ooxmlSniffs')]
    public function test_a_package_with_its_content_types_and_its_applications_folder_matches_under_any_unsure_sniff(string $sniffed): void
    {
        $pptx = $this->zip(['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<x/>']);

        $this->assertTrue(KitKinds::contentMatches($pptx, 'pptx', $sniffed));
        $this->assertFalse(KitKinds::contentMatches($pptx, 'docx', $sniffed), 'a PowerPoint package is not a Word one');
        $this->assertFalse(KitKinds::contentMatches($pptx, 'xlsx', $sniffed));
    }

    public function test_a_zip_without_content_types_or_without_the_applications_folder_does_not_match(): void
    {
        $this->assertFalse(KitKinds::contentMatches($this->zip(['ppt/presentation.xml' => '<x/>']), 'pptx', 'application/zip'));
        $this->assertFalse(KitKinds::contentMatches($this->zip(['[Content_Types].xml' => '<Types/>', 'docProps/app.xml' => '<x/>']), 'pptx', 'application/zip'), 'no ppt/ entry');
        $this->assertFalse(KitKinds::contentMatches($this->zip(['readme.txt' => 'hi']), 'pptx', 'application/octet-stream'));
        $this->assertFalse(KitKinds::contentMatches($this->zip(['[Content_Types].xml' => '<Types/>', 'xppt/a.xml' => '<x/>']), 'pptx', 'application/zip'), 'the folder is ppt/, not any name ending in it');
        $this->assertFalse(KitKinds::contentMatches($this->file('PK but not a zip'), 'pptx', 'application/zip'));
        $this->assertFalse(KitKinds::contentMatches($this->file("MZ\x90\x00" . str_repeat("\x00", 64)), 'pptx', 'application/octet-stream'));
        $this->assertFalse(KitKinds::contentMatches($this->file('<?php echo 1;'), 'pptx', 'application/octet-stream'));
        $this->assertFalse(KitKinds::contentMatches(sys_get_temp_dir() . '/there-is-no-such-file-' . uniqid(), 'pptx', 'application/zip'));
    }

    public function test_a_sniffed_type_that_cannot_be_an_office_package_refuses_even_a_real_package(): void
    {
        $pptx = $this->zip(['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<x/>']);

        foreach (['text/x-php', 'application/x-dosexec', 'text/plain', 'application/pdf', 'image/png', ''] as $sniffed) {
            $this->assertFalse(KitKinds::contentMatches($pptx, 'pptx', $sniffed), "sniffed as '{$sniffed}'");
        }
        $this->assertTrue(KitKinds::contentMatches($pptx, 'pptx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'any OOXML type is a type the package itself then confirms');
    }

    public function test_a_legacy_office_file_needs_the_ole_signature_and_a_sniffed_type_that_can_be_one(): void
    {
        $ole = $this->file("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\x00", 64));

        foreach (['doc', 'xls', 'ppt'] as $extension) {
            foreach (['application/octet-stream', 'application/CDFV2', 'application/x-ole-storage', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.ms-office'] as $sniffed) {
                $this->assertTrue(KitKinds::contentMatches($ole, $extension, $sniffed), "{$extension} sniffed as {$sniffed}");
            }
            foreach (['text/plain', 'application/x-dosexec', 'application/zip', ''] as $sniffed) {
                $this->assertFalse(KitKinds::contentMatches($ole, $extension, $sniffed), "{$extension} sniffed as '{$sniffed}'");
            }
            $this->assertFalse(KitKinds::contentMatches($this->file('random text'), $extension, 'application/octet-stream'));
            $this->assertFalse(KitKinds::contentMatches($this->file(substr("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 0, 7)), $extension, 'application/octet-stream'), 'a signature cut short');
            $this->assertFalse(KitKinds::contentMatches($this->file(''), $extension, 'application/octet-stream'));
            $this->assertFalse(KitKinds::contentMatches($this->zip(['[Content_Types].xml' => '<Types/>']), $extension, 'application/octet-stream'), 'a zip is not an OLE file');
        }
    }

    public function test_an_odt_package_has_a_mimetype_entry_that_says_so(): void
    {
        $this->assertTrue(KitKinds::contentMatches($this->zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<x/>']), 'odt', 'application/zip'));
        $this->assertTrue(KitKinds::contentMatches($this->zip(['mimetype' => "application/vnd.oasis.opendocument.text\n"]), 'odt', 'application/octet-stream'));
        $this->assertFalse(KitKinds::contentMatches($this->zip(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet']), 'odt', 'application/zip'));
        $this->assertFalse(KitKinds::contentMatches($this->zip(['content.xml' => '<x/>']), 'odt', 'application/zip'));
        $this->assertFalse(KitKinds::contentMatches($this->file('not a zip'), 'odt', 'application/zip'));
    }

    public function test_no_other_extension_is_judged_by_content_so_pdf_and_video_keep_the_sniff_based_check(): void
    {
        $anything = $this->file('%PDF-1.4');

        foreach (['pdf', 'png', 'mp4', 'webm', 'mov', 'txt', 'csv', 'rtf', 'exe', ''] as $extension) {
            $this->assertFalse(KitKinds::contentMatches($anything, $extension, 'application/pdf'), "'{$extension}'");
        }
    }
}
