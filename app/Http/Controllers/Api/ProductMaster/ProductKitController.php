<?php

namespace App\Http\Controllers\Api\ProductMaster;

use App\Domain\ProductMaster\Kit\KitKinds;
use App\Domain\ProductMaster\Kit\KitNotSetUp;
use App\Domain\ProductMaster\Kit\KitRefused;
use App\Domain\ProductMaster\Kit\KitRights;
use App\Domain\ProductMaster\Kit\ProductKit;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * The Sales Kit of a Product (27 Nov 2026; Docs/agentic-crm/briefs/SALES-KIT-CONTRACT.md): the demo presentations, documents, videos and images a sales person opens before a call.
 *
 * Every route asks for `platformright:/portfolio/sales-kit,<view|add|edit|delete>` (routes/product_master.php): the rights are the rows of the "Sales Kit" menu row, set in Role and
 * Permissions, and there is no administrator bypass. The organisation always comes from the caller's token. What the kit holds, how a file is filed in the Document Library and what is
 * refused is ProductKit's; this class reads the request, answers in the API's envelope and streams a file.
 *
 *   200/201 { status: 1, data }           422 { status: 0, message, code? }     404 { status: 0, message }
 *   503 { status: 0, code: 'not_set_up' }  when this host has not had the Sales Kit's database change yet
 */
class ProductKitController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /{id}/kit */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->run($request, fn (array $identity) => $this->ok((new ProductKit($identity))->overview($id) + ['can' => KitRights::all($identity)]));
    }

    /** POST /{id}/kit/files - multipart: `file`, and optionally `title`, `description`, `kind`. */
    public function addFile(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request, $id) {
            $kit = new ProductKit($identity);
            $kit->product($id);

            $maxKb = (int) config('documents.kit_max_upload_kb');
            $extensions = KitKinds::extensions();
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:' . implode(',', $extensions) . '|max:' . $maxKb,
                'title' => 'nullable|string|max:191',
                'description' => 'nullable|string|max:500',
                'kind' => 'nullable|string|in:' . implode(',', KitKinds::ALL),
            ], [
                'file.required' => 'Choose a file to add.',
                'file.file' => 'The file could not be uploaded. It may be larger than this server accepts.',
                'file.uploaded' => 'The file could not be uploaded. It may be larger than this server accepts.',
                'file.mimes' => 'That file type is not accepted. Use ' . implode(', ', $extensions) . '.',
                'file.max' => 'That file is larger than ' . $this->megabytes($maxKb) . '.',
                'title.max' => 'The title is too long (191 characters at most).',
                'description.max' => 'The description is too long (500 characters at most).',
                'kind.in' => 'Kind must be one of: ' . implode(', ', KitKinds::ALL) . '.',
            ]);
            if ($validator->fails()) {
                return $this->fail($validator->errors()->first(), 422);
            }

            $item = $kit->addFile($id, $request->file('file'), $request->only(['title', 'description', 'kind']));

            return $this->ok($item, 201, 'Added to the Sales Kit.');
        });
    }

    /** POST /{id}/kit/links - JSON { title, url, kind, description? }. */
    public function addLink(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request, $id) {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:191',
                'url' => 'required|string|max:500',
                'kind' => 'required|string',
                'description' => 'nullable|string|max:500',
            ], [
                'title.required' => 'Enter a title.',
                'title.max' => 'The title is too long (191 characters at most).',
                'url.required' => 'Enter the address of the link.',
                'url.max' => 'The address is too long (500 characters at most).',
                'kind.required' => 'Choose what kind of link this is.',
                'description.max' => 'The description is too long (500 characters at most).',
            ]);
            if ($validator->fails()) {
                (new ProductKit($identity))->product($id);

                return $this->fail($validator->errors()->first(), 422);
            }

            return $this->ok((new ProductKit($identity))->addLink($id, $validator->validated()), 201, 'Added to the Sales Kit.');
        });
    }

    /** POST /{id}/kit/from-library - JSON { documentId, title?, description?, kind?, makeShared? }. */
    public function addFromLibrary(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request, $id) {
            $validator = Validator::make($request->all(), [
                'documentId' => 'required|integer|min:1',
                'title' => 'nullable|string|max:191',
                'description' => 'nullable|string|max:500',
                'kind' => 'nullable|string',
                'makeShared' => 'nullable|boolean',
            ], [
                'documentId.required' => 'Choose a document from the library.',
                'documentId.integer' => 'Choose a document from the library.',
                'title.max' => 'The title is too long (191 characters at most).',
                'description.max' => 'The description is too long (500 characters at most).',
                'makeShared.boolean' => 'makeShared must be true or false.',
            ]);
            if ($validator->fails()) {
                (new ProductKit($identity))->product($id);

                return $this->fail($validator->errors()->first(), 422);
            }

            return $this->ok((new ProductKit($identity))->addFromLibrary($id, $validator->validated()), 201, 'Added to the Sales Kit.');
        });
    }

    /** GET /{id}/kit/library-search?q= */
    public function searchLibrary(Request $request, int $id): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request, $id) {
            $kit = new ProductKit($identity);
            $kit->product($id);
            $q = trim((string) $request->query('q', ''));
            if (mb_strlen($q) < 2) {
                return $this->fail('Type at least 2 letters to search the library.', 422);
            }

            return $this->ok($kit->searchLibrary($id, $q));
        });
    }

    /** PATCH /{id}/kit/{itemId} - JSON, any of { title, description, kind, sortOrder, url } (url for a link item only). */
    public function update(Request $request, int $id, int $itemId): JsonResponse
    {
        return $this->run($request, function (array $identity) use ($request, $id, $itemId) {
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|nullable|string|max:191',
                'description' => 'sometimes|nullable|string|max:500',
                'kind' => 'sometimes|nullable|string',
                'sortOrder' => 'sometimes|integer|between:-999999,999999',
                'url' => 'sometimes|nullable|string|max:500',
            ], [
                'title.max' => 'The title is too long (191 characters at most).',
                'description.max' => 'The description is too long (500 characters at most).',
                'sortOrder.integer' => 'The order must be a whole number.',
                'sortOrder.between' => 'The order must be a whole number.',
                'url.max' => 'The address is too long (500 characters at most).',
            ]);
            $kit = new ProductKit($identity);
            if ($validator->fails()) {
                $kit->item($id, $itemId);

                return $this->fail($validator->errors()->first(), 422);
            }

            return $this->ok($kit->update($id, $itemId, $request->only(['title', 'description', 'kind', 'sortOrder', 'url'])));
        });
    }

    /** DELETE /{id}/kit/{itemId} - the kit row goes (soft) with its library link; the library document stays. */
    public function destroy(Request $request, int $id, int $itemId): JsonResponse
    {
        return $this->run($request, fn (array $identity) => $this->ok(['id' => (new ProductKit($identity))->remove($id, $itemId)]));
    }

    /** GET /{id}/kit/{itemId}/download?inline=1 - the file's bytes from the document storage; 404 for a link item. */
    public function download(Request $request, int $id, int $itemId)
    {
        return $this->run($request, function (array $identity) use ($request, $id, $itemId) {
            $document = (new ProductKit($identity))->documentOf($id, $itemId);
            $storage = new DocumentStorageService();
            if (! $storage->exists($document->storage_path)) {
                return $this->fail('The file of this item is recorded but missing from storage.', 404);
            }

            $inline = $request->boolean('inline');
            if (! $inline) {
                $this->recordDownload((int) $document->id, (int) $identity['user_id'], $request);
            }
            $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=0, must-revalidate'];
            if (trim((string) $document->mime_type) !== '') {
                $headers['Content-Type'] = (string) $document->mime_type;
            }

            return Storage::disk($storage->disk())->response($document->storage_path, $this->fileName($document), $headers, $inline ? 'inline' : 'attachment');
        });
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Resolves the caller, runs the work and turns the domain's refusals into the API's answers.
     *
     * @param callable(array{user:object, user_id:int, sub_institute_id:int}): mixed $work
     */
    private function run(Request $request, callable $work)
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        try {
            return $work($identity);
        } catch (KitNotSetUp $e) {
            return response()->json(['status' => 0, 'code' => 'not_set_up', 'message' => $e->getMessage()], 503);
        } catch (KitRefused $e) {
            return $this->fail($e->getMessage(), $e->status, $e->reason);
        }
    }

    /** @param mixed $data */
    private function ok($data, int $status = 200, ?string $message = null): JsonResponse
    {
        return response()->json(['status' => 1] + ($message !== null ? ['message' => $message] : []) + ['data' => $data], $status);
    }

    private function fail(string $message, int $status, ?string $code = null): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message] + ($code !== null ? ['code' => $code] : []), $status);
    }

    private function megabytes(int $kb): string
    {
        $mb = $kb / 1024;

        return (floor($mb) === $mb ? (string) (int) $mb : number_format($mb, 1)) . ' MB';
    }

    /** The name the file is saved under: its own name from the library, without path or quote characters. */
    private function fileName(object $document): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]/', '', (string) $document->original_file_name));

        return $name !== '' ? $name : 'file.' . (pathinfo((string) $document->storage_path, PATHINFO_EXTENSION) ?: 'bin');
    }

    /** The library's own audit trail records a download; so does a kit download (not an inline preview, which a card may fetch many times). */
    private function recordDownload(int $documentId, int $userId, Request $request): void
    {
        try {
            DB::table('document_library_history')->insert([
                'document_id' => $documentId,
                'entry_type' => 'audit',
                'action' => 'downloaded',
                'details' => json_encode(['via' => 'sales_kit']),
                'ip_address' => mb_substr((string) $request->ip(), 0, 45),
                'created_by' => $userId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
