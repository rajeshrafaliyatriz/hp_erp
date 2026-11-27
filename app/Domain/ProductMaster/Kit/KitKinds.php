<?php

namespace App\Domain\ProductMaster\Kit;

/**
 * The rules of the Sales Kit that need no database: which category (`kind`) a file belongs to, what a person may change it to, which extensions the kit takes and what a link may point at.
 * Pure, so the rules are tested without a request (tests/Unit/SalesKitKindsTest.php).
 *
 *   presentation   ppt, pptx (and a pdf that is a deck)
 *   document       pdf, doc, docx, xls, xlsx, txt, rtf, odt, csv
 *   image          jpg, jpeg, png, webp, gif
 *   video          mp4, webm, mov
 *
 * The kind comes from the extension. A person may override it only where the extension cannot say: a pdf, ppt or pptx may be filed as a presentation or as a document.
 */
final class KitKinds
{
    public const PRESENTATION = 'presentation';

    public const DOCUMENT = 'document';

    public const VIDEO = 'video';

    public const IMAGE = 'image';

    /** @var list<string> */
    public const ALL = [self::PRESENTATION, self::DOCUMENT, self::VIDEO, self::IMAGE];

    private const BY_EXTENSION = [
        'ppt' => self::PRESENTATION, 'pptx' => self::PRESENTATION,
        'pdf' => self::DOCUMENT, 'doc' => self::DOCUMENT, 'docx' => self::DOCUMENT, 'xls' => self::DOCUMENT, 'xlsx' => self::DOCUMENT,
        'txt' => self::DOCUMENT, 'rtf' => self::DOCUMENT, 'odt' => self::DOCUMENT, 'csv' => self::DOCUMENT,
        'jpg' => self::IMAGE, 'jpeg' => self::IMAGE, 'png' => self::IMAGE, 'webp' => self::IMAGE, 'gif' => self::IMAGE,
        'mp4' => self::VIDEO, 'webm' => self::VIDEO, 'mov' => self::VIDEO,
    ];

    /** The extensions whose kind a person may choose between a presentation and a document. */
    private const EITHER = ['pdf', 'ppt', 'pptx'];

    /** @return list<string> the extensions the kit takes (config documents.kit_allowed_extensions), lower case */
    public static function extensions(): array
    {
        $list = array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) config('documents.kit_allowed_extensions')));

        return array_values(array_unique(array_filter($list, fn ($e) => $e !== '')));
    }

    /** The extension of a file name, lower case, without the dot ('' when it has none). */
    public static function extensionOf(string $fileName): string
    {
        return strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    }

    public static function forExtension(string $extension): ?string
    {
        return self::BY_EXTENSION[strtolower($extension)] ?? null;
    }

    /**
     * The kinds a file may be filed as. With a known extension: its own kind, plus the other of presentation and document for a pdf, ppt or pptx. With no usable extension (a library
     * document whose name has none): the kind it already has, plus the other of presentation and document when it is one of those two.
     *
     * @return list<string>
     */
    public static function allowedFor(?string $extension, ?string $current = null): array
    {
        $known = $extension !== null && $extension !== '';
        $own = $known ? (self::forExtension($extension) ?? self::DOCUMENT) : ($current ?? self::DOCUMENT);
        $either = $known ? in_array(strtolower($extension), self::EITHER, true) : in_array($own, [self::PRESENTATION, self::DOCUMENT], true);

        return $either ? [self::PRESENTATION, self::DOCUMENT] : [$own];
    }

    /** The kind a file is filed as: the one asked for when it is allowed for that extension, else (nothing asked) its own. A kind that is not allowed is a 422 that says why. */
    public static function choose(?string $extension, ?string $requested, ?string $current = null): string
    {
        $extension = $extension !== null ? strtolower($extension) : null;
        $known = $extension !== null && $extension !== '';
        $own = $known ? (self::forExtension($extension) ?? self::DOCUMENT) : ($current ?? self::DOCUMENT);
        $requested = $requested !== null ? strtolower(trim($requested)) : '';
        if ($requested === '') {
            return $own;
        }
        if (! in_array($requested, self::ALL, true)) {
            throw KitRefused::invalid('Kind must be one of: ' . implode(', ', self::ALL) . '.');
        }
        if (in_array($requested, self::allowedFor($extension, $current), true)) {
            return $requested;
        }

        $what = $known ? 'A .' . $extension . ' file' : 'This file';

        throw KitRefused::invalid("{$what} is a {$own}; it cannot be filed as a {$requested}.");
    }

    // ------------------------------------------------------------------ what a file really is

    /** The folder an OOXML package of each extension must hold (a docx has word/, a xlsx xl/, a pptx ppt/), and the type its extension has. */
    private const OOXML = [
        'docx' => ['dir' => 'word/', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['dir' => 'xl/', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['dir' => 'ppt/', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    /** The legacy binary Office formats (an OLE compound file) and the type each extension has. */
    private const OLE = [
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
    ];

    private const ODT_MIME = 'application/vnd.oasis.opendocument.text';

    /** What a zip package or a mismatched libmagic reports for a real Office file: a sniff that cannot tell, so the CONTENT decides. */
    private const UNSURE_SNIFFS = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

    /** The first 8 bytes of every OLE compound file (a .doc, .xls or .ppt). */
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * The family of an extension whose type PHP's file sniffing cannot be trusted for: `ooxml` (docx, xlsx, pptx: a zip package), `ole` (doc, xls, ppt: a compound file) and `odt`
     * (a zip package too). A pptx made by pptxgenjs is `application/octet-stream` to the fileinfo of one host and `application/zip` to another. Null for every other extension, whose
     * sniffed type is checked as before (Laravel's `mimes` rule).
     */
    public static function family(string $extension): ?string
    {
        $extension = strtolower($extension);

        return isset(self::OOXML[$extension]) ? 'ooxml' : (isset(self::OLE[$extension]) ? 'ole' : ($extension === 'odt' ? 'odt' : null));
    }

    /** The type an Office file of this extension is stored with, whatever the host's sniffing said; null for any other extension. */
    public static function officeMime(string $extension): ?string
    {
        $extension = strtolower($extension);

        return self::OOXML[$extension]['mime'] ?? self::OLE[$extension] ?? ($extension === 'odt' ? self::ODT_MIME : null);
    }

    /**
     * Whether the CONTENT of a file is what its extension says, for the three Office families (see family()); false for any other extension.
     *
     *   docx, xlsx, pptx   the sniffed type is the extension's own, any other OOXML type, a zip or a bare octet-stream, AND the file opens as a zip that holds `[Content_Types].xml` and
     *                      an entry of the folder of its application (word/, xl/, ppt/). A zip that is not an Office package, or a program or script renamed to .pptx, is refused.
     *   doc, xls, ppt      the sniffed type is msword, a vnd.ms-* type, an OLE storage or CDFV2 type or a bare octet-stream, AND the file starts with the OLE signature D0 CF 11 E0 A1 B1 1A E1.
     *   odt                a zip with a first `mimetype` entry of application/vnd.oasis.opendocument.text (the caller tries Laravel's own sniff-based check first when this is false)
     */
    public static function contentMatches(string $path, string $extension, string $sniffedMime): bool
    {
        $extension = strtolower($extension);
        $sniffed = strtolower(trim($sniffedMime));
        if (! is_file($path)) {
            return false;
        }

        return match (self::family($extension)) {
            'ooxml' => self::ooxmlSniffOk($extension, $sniffed) && self::isOoxmlPackage($path, self::OOXML[$extension]['dir']),
            'ole' => self::oleSniffOk($sniffed) && self::hasOleSignature($path),
            'odt' => in_array($sniffed, [...self::UNSURE_SNIFFS, self::ODT_MIME], true) && self::isOdtPackage($path),
            default => false,
        };
    }

    private static function ooxmlSniffOk(string $extension, string $sniffed): bool
    {
        return in_array($sniffed, self::UNSURE_SNIFFS, true) || $sniffed === self::OOXML[$extension]['mime'] || str_starts_with($sniffed, 'application/vnd.openxmlformats-officedocument.');
    }

    private static function oleSniffOk(string $sniffed): bool
    {
        return in_array($sniffed, ['application/msword', 'application/x-ole-storage', 'application/cdfv2', 'application/octet-stream'], true) || str_starts_with($sniffed, 'application/vnd.ms-');
    }

    private static function hasOleSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);

        return $head === self::OLE_SIGNATURE;
    }

    /** A zip that holds `[Content_Types].xml` and at least one entry under the folder of its application. Only the zip's directory is read, never an entry's bytes. */
    private static function isOoxmlPackage(string $path, string $folder): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }
            for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
                if (str_starts_with((string) $zip->getNameIndex($i), $folder)) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /** An OpenDocument text package: a zip whose `mimetype` entry says application/vnd.oasis.opendocument.text. */
    private static function isOdtPackage(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            $index = $zip->locateName('mimetype');
            if ($index === false || ((int) ($zip->statIndex($index)['size'] ?? 0)) > 256) {
                return false;
            }

            return trim((string) $zip->getFromIndex($index)) === self::ODT_MIME;
        } finally {
            $zip->close();
        }
    }

    /**
     * Why a link address is refused, or null when it is fine: an `https://` address with a host, or an address inside the app that starts with ONE slash (the interactive deck).
     * `http://`, `javascript:` and `//host` (which a browser reads as another site) are refused, and so are spaces, control characters and backslashes (a browser reads `\` as `/`).
     */
    public static function urlProblem(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'Enter the address of the link.';
        }
        if (mb_strlen($url) > 500) {
            return 'The address is too long (500 characters at most).';
        }
        if (preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return 'The address cannot contain spaces or backslashes.';
        }
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? 'An address inside the app starts with a single slash.' : null;
        }
        if (str_starts_with(strtolower($url), 'https://')) {
            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? null : 'The address needs a website name after https://.';
        }

        return 'The address must start with https:// or, for a page inside the app, with a single slash.';
    }
}
