<?php

namespace App\Domain\ProductMaster\Kit;

use App\Services\Documents\DocumentAccess;
use App\Services\Documents\DocumentShareService;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Sales Kit of a Product (27 Nov 2026; Docs/agentic-crm/briefs/SALES-KIT-CONTRACT.md): the demo presentations, documents, videos and images a sales person opens before a call.
 *
 * One row of g2g_product_kit_items per item, always owned by ONE organisation (`sub_institute_id`): every read and write is scoped by the caller's organisation, so a product that is
 * shared by every organisation (a master row of organisation 0, which only some hosts still have) takes per-organisation kit rows and one organisation's deck never shows in another.
 * Only a product of kind `product` has a kit, never a sub-product.
 *
 * A FILE item lives in the Document Library: a native document_library row (visibility and category `organization`, document type "Sales Kit"), its history row, and a
 * document_library_links row (`PRODUCT`), written in ONE transaction exactly like HasCrmDocuments::attachDocument, so the file is also found from the library's side. A LINK item holds an
 * address instead (an https address or a page inside the app, such as the interactive deck). Taking an item out of the kit soft-deletes the kit row and removes its link row; the
 * library document stays (whoever uploaded it removes it there, explicitly).
 *
 * The rights are not decided here: the routes ask for `platformright:/portfolio/sales-kit,<action>` (KitRights). This class decides what is visible and writable inside the organisation.
 */
final class ProductKit
{
    public const TABLE = 'g2g_product_kit_items';

    public const LINKED_TYPE = 'PRODUCT';

    public const DOCUMENT_TYPE = 'Sales Kit';

    /** Every table a kit read or write touches; a host that lacks one has not had a database change yet. */
    private const TABLES = [self::TABLE, 'g2g_product_master', 'document_library', 'document_library_history', 'document_library_links'];

    /** Whether this host has the share columns (read once per request). */
    private ?bool $sharing = null;

    /** @param array{user:object, user_id:int, sub_institute_id:int} $identity */
    public function __construct(private readonly array $identity)
    {
        if (! self::ready()) {
            throw new KitNotSetUp();
        }
    }

    /** Whether this host has every table the kit needs. */
    public static function ready(): bool
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function tenant(): int
    {
        return (int) $this->identity['sub_institute_id'];
    }

    private function userId(): int
    {
        return (int) $this->identity['user_id'];
    }

    // ------------------------------------------------------------------ the product

    /**
     * The product a kit belongs to. The organisation's own rows, and a row shared by every organisation (organisation 0); any other row is a 404, the same as one that does not exist.
     * A sub-product or a catalogue item has no kit (422).
     */
    public function product(int $productId): object
    {
        $tenant = $this->tenant();
        $row = $tenant > 0 && $productId > 0
            ? DB::table('g2g_product_master')->where('id', $productId)->whereIn('sub_institute_id', [0, $tenant])->first(['id', 'name', 'kind', 'sub_institute_id'])
            : null;
        if (! $row) {
            throw KitRefused::notFound();
        }
        if ($row->kind !== 'product') {
            throw KitRefused::invalid('A Sales Kit belongs to a product, not to a sub-product. Open the product to work on its kit.', 'not_a_product');
        }

        return $row;
    }

    // ------------------------------------------------------------------ reading

    /**
     * The kit of a product: its items (sort order, then newest first), the count per kind, and the limits an upload must keep to.
     *
     * @return array{product: array{id:int,name:string}, items: list<array<string,mixed>>, counts: array<string,int>, limits: array{maxUploadKb:int, extensions:list<string>}}
     */
    public function overview(int $productId): array
    {
        $product = $this->product($productId);
        $rows = $this->liveRows($productId)->orderBy('sort_order')->orderByDesc('created_at')->orderByDesc('id')->get();
        $items = $this->resources($rows);

        $counts = ['all' => count($items)] + array_fill_keys(KitKinds::ALL, 0);
        foreach ($items as $item) {
            $counts[$item['kind']] = ($counts[$item['kind']] ?? 0) + 1;
        }

        return [
            'product' => ['id' => (int) $product->id, 'name' => (string) $product->name],
            'items' => $items,
            'counts' => $counts,
            'limits' => self::limits(),
        ];
    }

    /** @return array{maxUploadKb:int, extensions:list<string>} */
    public static function limits(): array
    {
        return ['maxUploadKb' => (int) config('documents.kit_max_upload_kb'), 'extensions' => KitKinds::extensions()];
    }

    /** @return array<string, mixed> one item as the API shows it */
    public function item(int $productId, int $itemId): array
    {
        $this->product($productId);

        return $this->resources(collect([$this->row($productId, $itemId)]))[0];
    }

    /**
     * The library documents the caller may see that match the text, newest first, without the ones already in this product's kit. Native files only (a federated index row has no file here).
     *
     * @return list<array<string, mixed>>
     */
    public function searchLibrary(int $productId, string $q): array
    {
        $this->product($productId);
        $q = trim($q);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

        $inKit = $this->liveRows($productId)->whereNotNull('document_id')->select('document_id');
        $query = DocumentAccess::visibleTo(DB::table('document_library'), $this->userId(), $this->tenant(), $this->department())
            ->whereNull('deleted_at')
            ->whereNotNull('storage_path')
            ->whereNotIn('id', $inKit)
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('original_file_name', 'like', $like));

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit(20)
            ->get(['id', 'title', 'original_file_name', 'mime_type', 'size', 'visibility', 'owner_id'])
            ->map(fn ($d) => [
                'id' => (int) $d->id,
                'title' => (string) $d->title,
                'fileName' => $d->original_file_name,
                'mimeType' => $d->mime_type,
                'size' => $d->size !== null ? (int) $d->size : null,
                'visibility' => (string) $d->visibility,
                'ownedByMe' => (int) $d->owner_id === $this->userId(),
            ])->all();
    }

    /**
     * The library document behind a FILE item, for a download. A link item has no file (404); a document that was deleted from the library is a 404 too.
     */
    public function documentOf(int $productId, int $itemId): object
    {
        $this->product($productId);
        $row = $this->row($productId, $itemId);
        if ($row->document_id === null) {
            throw KitRefused::notFound('This item is a link, not a file.');
        }
        $document = DB::table('document_library')->where('id', $row->document_id)->where('sub_institute_id', $this->tenant())->whereNull('deleted_at')->first();
        if (! $document || empty($document->storage_path)) {
            throw KitRefused::notFound('The file of this item is no longer in the Document Library.');
        }

        return $document;
    }

    // ------------------------------------------------------------------ writing

    /**
     * Files an upload: the library document, its history row and its PRODUCT link, then the kit row, in one transaction.
     *
     * @param array{title?: ?string, description?: ?string, kind?: ?string} $meta
     * @return array<string, mixed>
     */
    public function addFile(int $productId, UploadedFile $file, array $meta = []): array
    {
        $product = $this->product($productId);

        $extension = KitKinds::extensionOf((string) $file->getClientOriginalName());
        if (! in_array($extension, KitKinds::extensions(), true)) {
            $extension = strtolower((string) $file->extension());
        }
        // A docx, xlsx, pptx, doc, xls or ppt is accepted by its CONTENT (the controller checks it, and so does this: nothing else may reach the library through this class).
        $family = KitKinds::family($extension);
        if (in_array($family, ['ooxml', 'ole'], true) && ! KitKinds::contentMatches((string) $file->getRealPath(), $extension, (string) $file->getMimeType())) {
            throw KitRefused::invalid('That file type is not accepted. Use ' . implode(', ', KitKinds::extensions()) . '.');
        }
        $kind = KitKinds::choose($extension, $meta['kind'] ?? null);
        $description = $this->description($meta['description'] ?? null);
        $givenTitle = $this->title($meta['title'] ?? null, false);

        // An Office file is stored under its own extension and type, whatever this host's fileinfo called it (octet-stream, zip): downloads and the screen then see a real PowerPoint type.
        $storage = new DocumentStorageService();
        $stored = $storage->storeUpload($file, $this->userId(), $family !== null ? $extension : null, KitKinds::officeMime($extension));
        $title = $givenTitle ?? $this->fromFileName((string) $stored['original_file_name']);
        $user = $this->userId();
        $tenant = $this->tenant();

        try {
            $itemId = DB::transaction(function () use ($stored, $title, $givenTitle, $user, $tenant, $productId, $product, $kind, $description) {
                // The library folder "Sales Kit / <Product>" (made when it is not there yet). A convenience, never a condition: null when this host cannot have one, and the upload goes on.
                $folderId = KitFolders::forProduct($tenant, $user, $productId, (string) $product->name);

                $documentId = DB::table('document_library')->insertGetId([
                    'sub_institute_id' => $tenant,
                    'owner_id' => $user,
                    'title' => $title,
                    'title_source' => $givenTitle !== null ? 'user' : 'filename',
                    'original_file_name' => $stored['original_file_name'],
                    'mime_type' => $stored['mime_type'],
                    'size' => $stored['size'],
                    'checksum_sha256' => $stored['checksum_sha256'],
                    'storage_path' => $stored['storage_path'],
                    'current_version' => 1,
                    'category' => 'organization',
                    'document_type' => self::DOCUMENT_TYPE,
                    'visibility' => 'organization',
                    'processing_status' => 'done',
                    'created_by' => $user,
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + ($folderId !== null ? ['folder_id' => $folderId] : []));

                DB::table('document_library_history')->insert([
                    'document_id' => $documentId,
                    'entry_type' => 'version',
                    'version_number' => 1,
                    'storage_path' => $stored['storage_path'],
                    'checksum_sha256' => $stored['checksum_sha256'],
                    'size' => $stored['size'],
                    'mime_type' => $stored['mime_type'],
                    'original_file_name' => $stored['original_file_name'],
                    'change_note' => 'Uploaded',
                    'created_by' => $user,
                    'created_at' => now(),
                ]);

                $this->link($documentId, $productId);

                return $this->insertItem($productId, $kind, $title, $description, $documentId, null);
            });
        } catch (\Throwable $e) {
            // The object is already in storage; a failed database write must not leave it there with nothing pointing at it.
            try {
                $storage->delete($stored['storage_path']);
            } catch (\Throwable) {
                // nothing more to do: the exception below is the one that matters
            }

            throw $e;
        }

        return $this->item($productId, $itemId);
    }

    /**
     * Adds a link item: an https address or a page inside the app (KitKinds::urlProblem).
     *
     * @param array{title?: ?string, url?: ?string, kind?: ?string, description?: ?string} $data
     * @return array<string, mixed>
     */
    public function addLink(int $productId, array $data): array
    {
        $this->product($productId);
        $title = $this->title($data['title'] ?? null, true);
        $kind = $this->kind($data['kind'] ?? null);
        $url = $this->url($data['url'] ?? null);
        $description = $this->description($data['description'] ?? null);

        $itemId = DB::transaction(fn () => $this->insertItem($productId, $kind, $title, $description, null, $url));

        return $this->item($productId, $itemId);
    }

    /**
     * Adds a document that is already in the library. The caller must be able to see it (DocumentAccess::canView, same organisation). A document that is not visible to the whole
     * organisation would show colleagues a card they cannot open, so it is refused (`not_shared`) unless the caller says `makeShared` AND owns it; then it is shared with the organisation.
     *
     * @param array{documentId?: mixed, title?: ?string, description?: ?string, kind?: ?string, makeShared?: bool} $data
     * @return array<string, mixed>
     */
    public function addFromLibrary(int $productId, array $data): array
    {
        $this->product($productId);
        $user = $this->userId();
        $tenant = $this->tenant();

        $document = DB::table('document_library')->where('id', (int) ($data['documentId'] ?? 0))->whereNull('deleted_at')->first();
        if (! $document || ! DocumentAccess::canView($document, $user, $tenant, $this->department())) {
            throw KitRefused::notFound('That document was not found.');
        }
        if (empty($document->storage_path)) {
            throw KitRefused::invalid('That document has no file in the library, so it cannot be added to a kit.', 'no_file');
        }
        if ($this->liveRows($productId)->where('document_id', $document->id)->exists()) {
            throw KitRefused::invalid('That document is already in this kit.', 'already_in_kit');
        }

        $shareNow = false;
        if ((string) $document->visibility !== 'organization') {
            if (empty($data['makeShared'])) {
                throw KitRefused::invalid('This document is not shared with the organization, so colleagues could not open it from the kit. Share it with the organization to add it.', 'not_shared');
            }
            if ((int) $document->owner_id !== $user) {
                throw KitRefused::invalid('Only the person who owns this document can share it with the organization.', 'not_shared');
            }
            $shareNow = true;
        }

        $extension = KitKinds::extensionOf((string) $document->original_file_name);
        $kind = KitKinds::choose($extension, $data['kind'] ?? null);
        $title = $this->title($data['title'] ?? null, false) ?? (trim((string) $document->title) !== '' ? mb_substr(trim((string) $document->title), 0, 191) : $this->fromFileName((string) $document->original_file_name));
        $description = $this->description($data['description'] ?? null);

        $itemId = DB::transaction(function () use ($document, $shareNow, $productId, $kind, $title, $description, $user) {
            if ($shareNow) {
                DB::table('document_library')->where('id', $document->id)->update(['visibility' => 'organization', 'updated_by' => $user, 'updated_at' => now()]);
                (new DocumentShareService())->syncViewPrincipals((int) $document->id);
                DB::table('document_library_history')->insert([
                    'document_id' => $document->id,
                    'entry_type' => 'audit',
                    'action' => 'updated',
                    'details' => json_encode(['changed_fields' => ['visibility'], 'via' => 'sales_kit']),
                    'created_by' => $user,
                    'created_at' => now(),
                ]);
            }
            $this->link((int) $document->id, $productId);

            return $this->insertItem($productId, $kind, $title, $description, (int) $document->id, null);
        });

        return $this->item($productId, $itemId);
    }

    /**
     * Changes an item: title, description, kind, order and, for a link item only, its address. Only the keys present are changed.
     *
     * @param array{title?: ?string, description?: ?string, kind?: ?string, sortOrder?: mixed, url?: ?string} $patch
     * @return array<string, mixed>
     */
    public function update(int $productId, int $itemId, array $patch): array
    {
        $this->product($productId);
        $row = $this->row($productId, $itemId);
        $isFile = $row->document_id !== null;
        $changes = [];

        if (array_key_exists('title', $patch)) {
            $changes['title'] = $this->title($patch['title'], true);
        }
        if (array_key_exists('description', $patch)) {
            $changes['description'] = $this->description($patch['description']);
        }
        if (array_key_exists('kind', $patch) && $patch['kind'] !== null && $patch['kind'] !== '') {
            if ($isFile) {
                $name = (string) DB::table('document_library')->where('id', $row->document_id)->value('original_file_name');
                $changes['kind'] = KitKinds::choose(KitKinds::extensionOf($name), $patch['kind'], (string) $row->kind);
            } else {
                $changes['kind'] = $this->kind($patch['kind']);
            }
        }
        if (array_key_exists('sortOrder', $patch)) {
            if (! is_int($patch['sortOrder']) && ! (is_string($patch['sortOrder']) && preg_match('/^-?\d{1,6}$/', $patch['sortOrder']))) {
                throw KitRefused::invalid('The order must be a whole number.');
            }
            $changes['sort_order'] = max(-999999, min(999999, (int) $patch['sortOrder']));
        }
        if (array_key_exists('url', $patch)) {
            if ($isFile) {
                throw KitRefused::invalid('A file has no link address. Add a link item instead.');
            }
            $changes['url'] = $this->url($patch['url']);
            // A page inside the app that is not a deck cannot be public: a link already made for this item stops with the change.
            if ($this->sharingReady() && ($row->share_token ?? null) !== null && ! KitShares::shareableUrl($changes['url'])) {
                $changes += ['share_token' => null, 'share_expires_at' => null, 'shared_at' => null, 'shared_by' => null];
            }
        }

        if ($changes !== []) {
            $changes['updated_by'] = $this->userId();
            $changes['updated_at'] = now();
            DB::table(self::TABLE)->where('id', $row->id)->where('sub_institute_id', $this->tenant())->update($changes);
        }

        return $this->item($productId, $itemId);
    }

    /** Takes an item out of the kit: the kit row is soft-deleted and its library link removed. The library document stays. */
    public function remove(int $productId, int $itemId): int
    {
        $this->product($productId);
        $row = $this->row($productId, $itemId);

        DB::transaction(function () use ($row, $productId) {
            // Removing an item ends its public link too (the public lookup also ignores a removed row).
            $ends = $this->sharingReady() ? ['share_token' => null, 'share_expires_at' => null, 'shared_at' => null, 'shared_by' => null] : [];
            DB::table(self::TABLE)->where('id', $row->id)->where('sub_institute_id', $this->tenant())->update(['deleted_at' => now(), 'updated_by' => $this->userId(), 'updated_at' => now()] + $ends);
            if ($row->document_id !== null) {
                DB::table('document_library_links')
                    ->where('document_id', $row->document_id)
                    ->where('linked_type', self::LINKED_TYPE)
                    ->where('linked_id', $productId)
                    ->where('sub_institute_id', $this->tenant())
                    ->delete();
            }
        });

        return (int) $row->id;
    }

    // ------------------------------------------------------------------ the public link

    /**
     * Makes the public link of an item, or makes a NEW one (the old token stops at once). `$days` is one of KitShares::DAYS (0 = never expires), no expiry when null.
     *
     * @return array{0: array<string, mixed>, 1: bool} the item with its `share`, and whether this made the first link (false: an existing link was replaced)
     */
    public function share(int $productId, int $itemId, mixed $days = null): array
    {
        $this->product($productId);
        $this->requireSharing();
        $row = $this->row($productId, $itemId);

        $days = $days === null || $days === '' ? KitShares::DEFAULT_DAYS : $days;
        if (! (is_int($days) || (is_string($days) && ctype_digit($days))) || ! in_array((int) $days, KitShares::DAYS, true)) {
            throw KitRefused::invalid('Choose how long the link works: 7 days, 30 days, 90 days, 1 year or no expiry.');
        }
        $days = (int) $days;

        if ($row->document_id !== null) {
            $document = DB::table('document_library')->where('id', $row->document_id)->where('sub_institute_id', $this->tenant())->whereNull('deleted_at')->first(['id', 'storage_path']);
            if (! $document || empty($document->storage_path)) {
                throw KitRefused::invalid('The file of this item is no longer in the Document Library, so it cannot be shared. Remove the item or add the file again.', 'missing_file');
            }
        } elseif (! KitShares::shareableUrl($row->url)) {
            throw KitRefused::invalid('A page inside the app needs a sign-in, so it cannot be shared with a link. Share a file, an https link or a presentation instead.', 'not_shareable');
        }

        $first = ($row->share_token ?? null) === null;
        DB::table(self::TABLE)->where('id', $row->id)->where('sub_institute_id', $this->tenant())->update([
            'share_token' => Str::random(48),
            'share_expires_at' => $days === 0 ? null : now()->addDays($days),
            'shared_at' => now(),
            'shared_by' => $this->userId(),
        ]);

        return [$this->item($productId, $itemId), $first];
    }

    /** Stops the public link of an item (the token is forgotten). Doing it again, or on an item that was never shared, changes nothing. */
    public function stopSharing(int $productId, int $itemId): array
    {
        $this->product($productId);
        $this->requireSharing();
        $row = $this->row($productId, $itemId);

        if (($row->share_token ?? null) !== null) {
            DB::table(self::TABLE)->where('id', $row->id)->where('sub_institute_id', $this->tenant())->update(['share_token' => null, 'share_expires_at' => null, 'shared_at' => null, 'shared_by' => null]);
        }

        return $this->item($productId, $itemId);
    }

    /** Whether this host has the four share columns; the kit works without them (no `share` in an item, and the share routes answer 503). */
    private function sharingReady(): bool
    {
        if ($this->sharing === null) {
            try {
                // One read of the table's columns (what Schema::hasColumn does for each), not four.
                $have = array_map('strtolower', Schema::getColumnListing(self::TABLE));
                $this->sharing = array_diff(['share_token', 'share_expires_at', 'shared_at', 'shared_by'], $have) === [];
            } catch (\Throwable) {
                $this->sharing = false;
            }
        }

        return $this->sharing;
    }

    private function requireSharing(): void
    {
        if (! $this->sharingReady()) {
            throw new KitNotSetUp('Sharing a link is not set up on this server yet (its database change has not been applied).');
        }
    }

    /** @return array{token: string, expiresAt: ?string, sharedAt: ?string, expired: bool}|null */
    private function shareOf(object $row): ?array
    {
        $token = $row->share_token ?? null;
        if ($token === null || $token === '') {
            return null;
        }

        return [
            'token' => (string) $token,
            'expiresAt' => $this->iso($row->share_expires_at ?? null),
            'sharedAt' => $this->iso($row->shared_at ?? null),
            'expired' => KitShares::expired($row->share_expires_at ?? null),
        ];
    }

    // ------------------------------------------------------------------ plumbing

    /** The organisation's own live kit rows of one product. */
    private function liveRows(int $productId)
    {
        return DB::table(self::TABLE)->where('sub_institute_id', $this->tenant())->where('product_master_id', $productId)->whereNull('deleted_at');
    }

    private function row(int $productId, int $itemId): object
    {
        $row = $this->liveRows($productId)->where('id', $itemId)->first();
        if (! $row) {
            throw KitRefused::notFound('That item was not found.');
        }

        return $row;
    }

    private function insertItem(int $productId, string $kind, string $title, ?string $description, ?int $documentId, ?string $url): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'sub_institute_id' => $this->tenant(),
            'product_master_id' => $productId,
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'document_id' => $documentId,
            'url' => $url,
            'sort_order' => 0,
            'created_by' => $this->userId(),
            'updated_by' => $this->userId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The document's PRODUCT link row (one per document and product); a second one is never written. */
    private function link(int $documentId, int $productId): void
    {
        $exists = DB::table('document_library_links')->where('document_id', $documentId)->where('linked_type', self::LINKED_TYPE)->where('linked_id', $productId)->exists();
        if (! $exists) {
            DB::table('document_library_links')->insert([
                'document_id' => $documentId,
                'linked_type' => self::LINKED_TYPE,
                'linked_id' => $productId,
                'sub_institute_id' => $this->tenant(),
                'created_by' => $this->userId(),
                'created_at' => now(),
            ]);
        }
    }

    private function department(): ?int
    {
        $value = DB::table('tbluser')->where('id', $this->userId())->value('department_id');

        return $value ? (int) $value : null;
    }

    private function title(mixed $value, bool $required): ?string
    {
        $title = trim((string) $value);
        if ($title === '') {
            if ($required) {
                throw KitRefused::invalid('Enter a title.');
            }

            return null;
        }
        if (mb_strlen($title) > 191) {
            throw KitRefused::invalid('The title is too long (191 characters at most).');
        }

        return $title;
    }

    /** The default title of a file: its name without the extension (at most 191 characters, never empty). */
    private function fromFileName(string $fileName): string
    {
        $name = trim(mb_substr((string) pathinfo($fileName, PATHINFO_FILENAME), 0, 191));

        return $name !== '' ? $name : 'Untitled';
    }

    private function description(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 500) {
            throw KitRefused::invalid('The description is too long (500 characters at most).');
        }

        return $text;
    }

    private function kind(mixed $value): string
    {
        $kind = strtolower(trim((string) $value));
        if (! in_array($kind, KitKinds::ALL, true)) {
            throw KitRefused::invalid('Kind must be one of: ' . implode(', ', KitKinds::ALL) . '.');
        }

        return $kind;
    }

    private function url(mixed $value): string
    {
        $url = trim((string) $value);
        $problem = KitKinds::urlProblem($url);
        if ($problem !== null) {
            throw KitRefused::invalid($problem);
        }

        return $url;
    }

    /**
     * The API's item for each row: the file's name, type and size come from the library document; a document that was deleted from the library leaves `missing: true` with no file
     * details instead of failing the whole list.
     *
     * @param Collection<int, object> $rows
     * @return list<array<string, mixed>>
     */
    private function resources(Collection $rows): array
    {
        $documentIds = $rows->pluck('document_id')->filter()->unique()->values()->all();
        $documents = $documentIds === [] ? collect() : DB::table('document_library')->whereIn('id', $documentIds)->where('sub_institute_id', $this->tenant())->whereNull('deleted_at')
            ->get(['id', 'original_file_name', 'mime_type', 'size'])->keyBy('id');
        $userIds = $rows->pluck('created_by')->filter()->unique()->values()->all();
        $people = $userIds === [] ? collect() : DB::table('tbluser')->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $withShare = $this->sharingReady();

        return $rows->map(function ($r) use ($documents, $people, $withShare) {
            $isFile = $r->document_id !== null;
            $document = $isFile ? $documents->get((int) $r->document_id) : null;
            $person = $r->created_by !== null ? $people->get((int) $r->created_by) : null;
            $name = $person ? trim(trim((string) $person->first_name) . ' ' . trim((string) $person->last_name)) : '';

            $item = [
                'id' => (int) $r->id,
                'kind' => (string) $r->kind,
                'title' => (string) $r->title,
                'description' => $r->description,
                'source' => $isFile ? 'file' : 'link',
                'documentId' => $isFile ? (int) $r->document_id : null,
                'fileName' => $document ? $document->original_file_name : null,
                'mimeType' => $document ? $document->mime_type : null,
                'size' => $document && $document->size !== null ? (int) $document->size : null,
                'url' => $r->url,
                'sortOrder' => (int) $r->sort_order,
                'createdBy' => $r->created_by !== null ? (int) $r->created_by : null,
                'createdByName' => $name !== '' ? $name : null,
                'createdAt' => $this->iso($r->created_at),
                'updatedAt' => $this->iso($r->updated_at),
                'missing' => $isFile && ! $document,
            ];

            // `share` is there only on a host that has the share columns: null when the item is not shared.
            return $withShare ? $item + ['share' => $this->shareOf($r)] : $item;
        })->values()->all();
    }

    private function iso(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toIso8601String();
    }
}
