<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\ProductMaster\Kit\KitShares;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The PUBLIC side of a Sales Kit share link (Docs/agentic-crm/briefs/SALES-KIT-SHARE-CONTRACT.md, part B): `GET /api/public/sales-kit/{token}` says what the link is, and
 * `GET /api/public/sales-kit/{token}/file` hands over the bytes of a file item. NO sign-in, NO session, NO token of a user: the secret in the address is all there is, so
 * these routes carry only `throttle` (routes/product_master.php) and never `api.token`.
 *
 * EVERY refusal is the same plain 404 with the same body and the same headers (KitShares::UNAVAILABLE): an unknown token, one of the wrong shape, a stopped or replaced link,
 * an expired one, an item that was removed, a file that is gone from the library, a host that has no share columns. A caller cannot tell which, so a token cannot be probed.
 * What is answered holds nothing private: no organization id, no user id, no library document id (KitShares::publicView).
 *
 * The file is never cached, never indexed, never sent a referrer, and is shown inline in the browser ONLY when `?inline=1` is asked for AND the type is a pdf, an image or a
 * video; a PowerPoint, Word, Excel or text file is always a download, whatever is asked.
 */
class ProductKitPublicController extends Controller
{
    /** GET /api/public/sales-kit/{token} */
    public function show(Request $request, string $token): JsonResponse
    {
        $share = KitShares::resolve($token);
        if ($share === null) {
            return $this->unavailable();
        }

        return $this->withHeaders(response()->json(['status' => 1, 'data' => KitShares::publicView($share)]));
    }

    /** GET /api/public/sales-kit/{token}/file?inline=1 */
    public function file(Request $request, string $token)
    {
        $share = KitShares::resolve($token);
        $document = $share['document'] ?? null;
        if ($share === null || $document === null) {
            return $this->unavailable();   // a link item has no file: the same answer
        }

        $storage = new DocumentStorageService();
        if (! $storage->exists($document->storage_path)) {
            return $this->unavailable();
        }

        $inline = $request->boolean('inline') && KitShares::inlineSafe($document->mime_type);
        if (! $inline) {
            $this->recordDownload((int) $document->id, $request);
        }
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        if (trim((string) $document->mime_type) !== '') {
            $headers['Content-Type'] = (string) $document->mime_type;
        }

        return Storage::disk($storage->disk())->response($document->storage_path, KitShares::downloadName($document), $headers, $inline ? 'inline' : 'attachment');
    }

    /** The one refusal: the same status, body and headers whatever the reason. */
    private function unavailable(): JsonResponse
    {
        return $this->withHeaders(response()->json(['status' => 0, 'message' => KitShares::UNAVAILABLE], 404));
    }

    private function withHeaders(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** A download from a public link is recorded in the library's own history, with nobody as the actor and the address it came from. */
    private function recordDownload(int $documentId, Request $request): void
    {
        try {
            DB::table('document_library_history')->insert([
                'document_id' => $documentId,
                'entry_type' => 'audit',
                'action' => 'downloaded',
                'details' => json_encode(['via' => 'sales_kit_public']),
                'ip_address' => mb_substr((string) $request->ip(), 0, 45),
                'created_by' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
