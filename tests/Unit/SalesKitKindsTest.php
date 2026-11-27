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
}
