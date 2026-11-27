<?php

namespace App\Domain\ProductMaster\Kit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The public share link of a Sales Kit item (Docs/agentic-crm/briefs/SALES-KIT-SHARE-CONTRACT.md, part B): the rules that need no request, and the one lookup the public routes make.
 *
 * A link is a secret token (48 random letters and digits) on the kit row (`share_token`, with `share_expires_at`, `shared_at`, `shared_by`). Anybody holding it may open THAT ONE item with no
 * sign-in, until it expires or a manager stops it or makes a new one (the old token is gone at once). The public side answers the same plain 404 for every refusal, so a caller cannot tell an
 * unknown token from a stopped, expired or removed one, or from a host that has no such columns.
 *
 * What can be shared: a file (its library document must still be there), an external `https://` link, and a deck: a link item whose address is `/signals/portfolio/deck/<key>`, the public
 * presentation page of the app. Any other address inside the app (`/portfolio/...`) is a page that needs a sign-in, so it cannot be public.
 */
final class KitShares
{
    /** The days a link may work for; 0 is no expiry, which is the default (the owner, 27 Nov 2026: a link that does not expire; it ends when a manager stops it or makes a new one). */
    public const DAYS = [7, 30, 90, 365, 0];

    public const DEFAULT_DAYS = 0;

    /** Str::random(48): letters and digits only, so the token is safe in an address. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{48}$/';

    /** The one answer to every refusal on the public side. */
    public const UNAVAILABLE = 'This link is not available.';

    /** Types a browser may show inline from a link (everything else is always a download): pdf, the four image types, and the three video types. */
    private const INLINE_SAFE = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'];

    /** The key of a deck address `/signals/portfolio/deck/<key>`, or null for any other address. */
    public static function deckKey(?string $url): ?string
    {
        return $url !== null && preg_match('#^/signals/portfolio/deck/([A-Za-z0-9][A-Za-z0-9_-]{0,63})$#', trim($url), $m) === 1 ? $m[1] : null;
    }

    /** Whether a link item's address may be public: an https address, or a deck. */
    public static function shareableUrl(?string $url): bool
    {
        $url = trim((string) $url);

        return $url !== '' && (str_starts_with(strtolower($url), 'https://') || self::deckKey($url) !== null);
    }

    public static function inlineSafe(?string $mimeType): bool
    {
        return in_array(strtolower(trim((string) $mimeType)), self::INLINE_SAFE, true);
    }

    public static function wellFormed(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function expired(?string $expiresAt): bool
    {
        return $expiresAt !== null && $expiresAt !== '' && ! now()->lt(Carbon::parse($expiresAt));
    }

    /** The name a file is saved under: its own name from the library, without path or quote characters. */
    public static function downloadName(object $document): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]/', '', (string) $document->original_file_name));

        return $name !== '' ? $name : 'file.' . (pathinfo((string) $document->storage_path, PATHINFO_EXTENSION) ?: 'bin');
    }

    /**
     * The live share behind a token: the kit row, its Product and (for a file) its library document, or null for ANY reason it is not available. Nothing is queried for a token of the wrong shape.
     * The database compares text without regard to case, so the row's own token is compared again exactly.
     *
     * @return array{item: object, product: object, document: ?object}|null
     */
    public static function resolve(string $token): ?array
    {
        if (! self::wellFormed($token)) {
            return null;
        }

        try {
            $item = DB::table(ProductKit::TABLE)->where('share_token', $token)->whereNull('deleted_at')->first();
            if (! $item || ! hash_equals((string) $item->share_token, $token) || self::expired($item->share_expires_at)) {
                return null;
            }
            $product = DB::table('g2g_product_master')->where('id', $item->product_master_id)->whereIn('sub_institute_id', [0, (int) $item->sub_institute_id])->where('kind', 'product')->first(['id', 'name']);
            if (! $product) {
                return null;
            }
            if ($item->document_id !== null) {
                $document = DB::table('document_library')->where('id', $item->document_id)->where('sub_institute_id', $item->sub_institute_id)->whereNull('deleted_at')->first();

                return $document && ! empty($document->storage_path) ? ['item' => $item, 'product' => $product, 'document' => $document] : null;
            }

            return self::shareableUrl($item->url) ? ['item' => $item, 'product' => $product, 'document' => null] : null;
        } catch (QueryException) {
            // A host without the table or the share columns has nothing to share: the same answer as any other refusal.
            return null;
        }
    }

    /**
     * What the public page may know about the item. No organization id, no user id, no document id, no creator: only what it shows.
     *
     * @param array{item: object, product: object, document: ?object} $share
     * @return array<string, mixed>
     */
    public static function publicView(array $share): array
    {
        $item = $share['item'];
        $document = $share['document'];
        $url = $document === null ? trim((string) $item->url) : '';

        return [
            'title' => (string) $item->title,
            'description' => $item->description,
            'kind' => (string) $item->kind,
            'source' => $document !== null ? 'file' : 'link',
            'productName' => (string) $share['product']->name,
            'fileName' => $document ? $document->original_file_name : null,
            'mimeType' => $document ? $document->mime_type : null,
            'size' => $document && $document->size !== null ? (int) $document->size : null,
            'url' => str_starts_with(strtolower($url), 'https://') ? $url : null,
            'deck' => $document === null ? self::deckKey($url) : null,
            'expiresAt' => $item->share_expires_at !== null && $item->share_expires_at !== '' ? Carbon::parse($item->share_expires_at)->toIso8601String() : null,
        ];
    }
}
