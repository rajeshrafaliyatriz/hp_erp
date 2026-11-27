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
