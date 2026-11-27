<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesKitWorld;
use Tests\TestCase;

/**
 * The Sales Kit of a Product (27 Nov 2026; Docs/agentic-crm/briefs/SALES-KIT-CONTRACT.md), through its API, all eight routes of ProductKitController:
 *
 *   GET    api/product-master/{id}/kit                        show
 *   GET    api/product-master/{id}/kit/library-search         searchLibrary
 *   POST   api/product-master/{id}/kit/files                  addFile
 *   POST   api/product-master/{id}/kit/links                  addLink
 *   POST   api/product-master/{id}/kit/from-library           addFromLibrary
 *   GET    api/product-master/{id}/kit/{itemId}/download      download
 *   PATCH  api/product-master/{id}/kit/{itemId}               update
 *   DELETE api/product-master/{id}/kit/{itemId}               destroy
 *
 * The tables come from the real migrations on in-memory sqlite (the document tables are hand-made copies, see BuildsSalesKitWorld); files go to a faked disk, never to the real bucket.
 * The route map with each route's right is in ProductMasterRouteMapTest, the grants command in SalesKitGrantsCommandTest.
 */
class SalesKitApiTest extends TestCase
{
    use BuildsSalesKitWorld;

    private const MIMES = [
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => self::PPTX, 'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'txt' => 'text/plain', 'rtf' => 'application/rtf',
        'odt' => 'application/vnd.oasis.opendocument.text', 'csv' => 'text/csv', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesKitWorld();
    }

    private function kitUrl(int $product, string $tail = ''): string
    {
        return "/api/product-master/{$product}/kit" . $tail;
    }

    /** @return array<string, mixed> the JSON of a 201 upload */
    private function uploaded(int $product, array $h, string $name = 'deck.pptx', array $extra = [], ?UploadedFile $file = null): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $this->post($this->kitUrl($product, '/files'), ['file' => $file ?? $this->upload($name, self::MIMES[$ext] ?? 'application/octet-stream')] + $extra, $h + ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    private function link(int $product, array $h, array $body = []): array
    {
        return $this->postJson($this->kitUrl($product, '/links'), $body + ['title' => 'Interactive deck', 'url' => '/signals/portfolio/deck/scholarclone', 'kind' => 'presentation'], $h)->assertCreated()->json('data');
    }

    // ---- reading ----

    public function test_an_empty_kit_answers_the_envelope_with_zero_counts_the_callers_rights_and_the_limits(): void
    {
        $p = $this->kitProduct(self::T);
        $viewer = $this->kitUser(self::T, ['view']);

        $res = $this->getJson($this->kitUrl($p), $viewer['h'])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame(['id' => $p, 'name' => 'ScholarClone'], $res->json('data.product'));
        $this->assertSame([], $res->json('data.items'));
        $this->assertSame(['all' => 0, 'presentation' => 0, 'document' => 0, 'video' => 0, 'image' => 0], $res->json('data.counts'));
        $this->assertSame(['view' => true, 'add' => false, 'edit' => false, 'delete' => false], $res->json('data.can'));
        $this->assertSame(102400, $res->json('data.limits.maxUploadKb'));
        $this->assertSame(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'webm', 'mov'], $res->json('data.limits.extensions'));
        $this->getJson($this->kitUrl($p))->assertStatus(401);
    }

    public function test_a_file_item_has_the_shape_the_screen_reads_and_a_link_item_has_none_of_the_file_fields(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $file = $this->uploaded($p, $m['h'], 'deck.pptx', ['description' => 'The 28-slide deck']);
        $link = $this->link($p, $m['h'], ['description' => 'Opens in the browser']);

        $this->assertSame(
            ['id', 'kind', 'title', 'description', 'source', 'documentId', 'fileName', 'mimeType', 'size', 'url', 'sortOrder', 'createdBy', 'createdByName', 'createdAt', 'updatedAt', 'missing'],
            array_keys($file)
        );
        $this->assertSame(['presentation', 'deck', 'The 28-slide deck', 'file', 'deck.pptx', self::PPTX, 20 * 1024, null, 0, $m['id'], 'Meera Shah', false],
            [$file['kind'], $file['title'], $file['description'], $file['source'], $file['fileName'], $file['mimeType'], $file['size'], $file['url'], $file['sortOrder'], $file['createdBy'], $file['createdByName'], $file['missing']]);
        $this->assertIsInt($file['documentId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $file['createdAt']);

        $this->assertSame(['link', null, null, null, null, '/signals/portfolio/deck/scholarclone', false, 'Interactive deck', 'Opens in the browser'],
            [$link['source'], $link['documentId'], $link['fileName'], $link['mimeType'], $link['size'], $link['url'], $link['missing'], $link['title'], $link['description']]);

        $items = $this->getJson($this->kitUrl($p), $m['h'])->assertOk()->json('data.items');
        $this->assertSame(['link', 'file'], array_column($items, 'source'), 'sort order is equal, so the newest item is first');
    }

    // ---- adding a file ----

    public function test_an_uploaded_file_is_written_to_the_library_with_its_history_and_its_product_link_and_to_the_kit_in_one_go(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        $item = $this->uploaded($p, $m['h']);

        $doc = DB::table('document_library')->where('id', $item['documentId'])->first();
        $this->assertSame([self::T, $m['id'], 'organization', 'organization', 'Sales Kit', 'done', 'deck.pptx', 1], [(int) $doc->sub_institute_id, (int) $doc->owner_id, $doc->visibility, $doc->category, $doc->document_type, $doc->processing_status, $doc->original_file_name, (int) $doc->current_version]);
        $this->assertSame('deck', $doc->title);
        $this->assertSame('filename', $doc->title_source);
        $this->assertSame($m['id'], (int) $doc->created_by);
        Storage::disk(config('documents.disk'))->assertExists($doc->storage_path);
        $this->assertStringStartsWith('private/document_library/', $doc->storage_path);

        $history = DB::table('document_library_history')->where('document_id', $doc->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(['version', 1, 'Uploaded', $doc->storage_path], [$history[0]->entry_type, (int) $history[0]->version_number, $history[0]->change_note, $history[0]->storage_path]);

        $link = DB::table('document_library_links')->where('document_id', $doc->id)->get();
        $this->assertCount(1, $link);
        $this->assertSame(['PRODUCT', $p, self::T, $m['id']], [$link[0]->linked_type, (int) $link[0]->linked_id, (int) $link[0]->sub_institute_id, (int) $link[0]->created_by]);

        $kit = DB::table('g2g_product_kit_items')->where('id', $item['id'])->first();
        $this->assertSame([self::T, $p, 'presentation', (int) $doc->id, null, null], [(int) $kit->sub_institute_id, (int) $kit->product_master_id, $kit->kind, (int) $kit->document_id, $kit->url, $kit->deleted_at]);
        $this->assertSame([1, 1, 1, 1], $this->kitCounts());
    }

    public function test_an_upload_takes_the_title_and_description_it_is_given_else_the_file_name_without_its_extension(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        $named = $this->uploaded($p, $m['h'], 'ScholarClone-Client-Presentation.pptx');
        $given = $this->uploaded($p, $m['h'], 'x.pdf', ['title' => '  Pricing sheet  ', 'description' => 'For the second call']);

        $this->assertSame('ScholarClone-Client-Presentation', $named['title']);
        $this->assertNull($named['description']);
        $this->assertSame(['Pricing sheet', 'For the second call'], [$given['title'], $given['description']]);
        $this->assertSame('user', DB::table('document_library')->where('id', $given['documentId'])->value('title_source'));
        $this->assertSame('Pricing sheet', DB::table('document_library')->where('id', $given['documentId'])->value('title'));

        $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('y.pdf', 'application/pdf'), 'title' => str_repeat('a', 192)], $m['h'] + ['Accept' => 'application/json'])->assertStatus(422);
    }

    /** @return array<string, array{string, string}> */
    public static function kindsByExtension(): array
    {
        $kinds = ['ppt' => 'presentation', 'pptx' => 'presentation', 'pdf' => 'document', 'doc' => 'document', 'docx' => 'document', 'xls' => 'document', 'xlsx' => 'document', 'txt' => 'document', 'rtf' => 'document', 'odt' => 'document', 'csv' => 'document',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image', 'gif' => 'image', 'mp4' => 'video', 'webm' => 'video', 'mov' => 'video'];

        $cases = [];
        foreach ($kinds as $extension => $kind) {
            $cases[".{$extension} is a {$kind}"] = [$extension, $kind];
        }

        return $cases;
    }

    #[DataProvider('kindsByExtension')]
    public function test_the_kind_comes_from_the_extension_and_every_kit_extension_is_accepted_including_video(string $extension, string $kind): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        $item = $this->uploaded($p, $m['h'], "file.{$extension}");

        $this->assertSame($kind, $item['kind']);
        $this->assertSame(self::MIMES[$extension], $item['mimeType']);
    }

    public function test_a_kind_may_be_chosen_only_where_the_extension_cannot_say_it_pdf_ppt_and_pptx_between_presentation_and_document(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        $this->assertSame('presentation', $this->uploaded($p, $m['h'], 'deck.pdf', ['kind' => 'presentation'])['kind']);
        $this->assertSame('document', $this->uploaded($p, $m['h'], 'notes.pptx', ['kind' => 'document'])['kind']);
        $this->assertSame('document', $this->uploaded($p, $m['h'], 'old.ppt', ['kind' => 'document'])['kind']);
        $this->assertSame('document', $this->uploaded($p, $m['h'], 'a.docx', ['kind' => 'document'])['kind'], 'the kind it already is is always accepted');
        $before = $this->kitCounts();

        foreach ([['a.docx', 'presentation'], ['clip.mp4', 'document'], ['logo.png', 'video'], ['clip.mov', 'presentation'], ['sheet.xlsx', 'image'], ['deck.pdf', 'banana']] as [$name, $kind]) {
            $res = $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload($name, self::MIMES[pathinfo($name, PATHINFO_EXTENSION)]), 'kind' => $kind], $m['h'] + ['Accept' => 'application/json']);
            $res->assertStatus(422);
            $this->assertNotSame('', $res->json('message'));
        }
        $this->assertSame($before, $this->kitCounts(), 'a refused kind writes nothing');
    }

    public function test_a_type_the_kit_does_not_take_is_refused_and_nothing_is_stored(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        foreach ([['setup.exe', 'application/x-msdownload'], ['run.php', 'application/x-httpd-php'], ['page.html', 'text/html'], ['logo.svg', 'image/svg+xml'], ['pack.zip', 'application/zip']] as [$name, $mime]) {
            $res = $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload($name, $mime)], $m['h'] + ['Accept' => 'application/json'])->assertStatus(422);
            $this->assertStringContainsString('not accepted', $res->json('message'));
            $this->assertStringContainsString('mp4', $res->json('message'), 'the message names what is accepted');
        }
        $this->assertSame([0, 0, 0, 0], $this->kitCounts());
        $this->assertSame([], Storage::disk(config('documents.disk'))->allFiles());
        $res = $this->post($this->kitUrl($p, '/files'), [], $m['h'] + ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame('Choose a file to add.', $res->json('message'));
    }

    public function test_the_kit_has_its_own_size_limit_and_the_librarys_own_limits_are_not_changed(): void
    {
        $this->assertSame(102400, config('documents.kit_max_upload_kb'));
        $this->assertSame(51200, config('documents.max_upload_kb'), 'the library keeps its 50 MB');
        $this->assertSame('pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv,jpg,jpeg,png,webp', config('documents.allowed_extensions'), 'the library still refuses video');

        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        // 60,000 KB: over the library's cap, under the kit's.
        $this->uploaded($p, $m['h'], 'film.mp4', [], $this->upload('film.mp4', 'video/mp4', 60000));
        $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('film.mp4', 'video/mp4', 102401)], $m['h'] + ['Accept' => 'application/json'])->assertStatus(422);

        config(['documents.kit_max_upload_kb' => 1000]);
        $this->uploaded($p, $m['h'], 'small.mp4', [], $this->upload('small.mp4', 'video/mp4', 900));
        $res = $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('big.mp4', 'video/mp4', 2000)], $m['h'] + ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('larger than', $res->json('message'));
        $this->assertSame(2, DB::table('g2g_product_kit_items')->count(), 'only the two that fit were filed');
    }

    public function test_the_same_content_filed_twice_is_not_refused_and_the_same_deck_may_sit_in_two_products(): void
    {
        $a = $this->kitProduct(self::T, 'ScholarClone');
        $b = $this->kitProduct(self::T, 'Enterprise Brain');
        $m = $this->kitManager();
        $bytes = fn () => UploadedFile::fake()->createWithContent('deck.pdf', '%PDF-1.4 the very same bytes');

        $this->uploaded($a, $m['h'], 'deck.pdf', [], $bytes());
        $this->uploaded($a, $m['h'], 'deck.pdf', [], $bytes());
        $this->uploaded($b, $m['h'], 'deck.pdf', [], $bytes());

        $this->assertSame(3, DB::table('document_library')->count());
        $this->assertSame(1, DB::table('document_library')->distinct()->count('checksum_sha256'));
        $this->assertSame([2, 1], [DB::table('g2g_product_kit_items')->where('product_master_id', $a)->count(), DB::table('g2g_product_kit_items')->where('product_master_id', $b)->count()]);
    }

    // ---- adding a link ----

    public function test_a_link_item_takes_an_https_address_or_a_page_inside_the_app(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        $web = $this->link($p, $m['h'], ['title' => 'Walkthrough on YouTube', 'url' => 'https://www.youtube.com/watch?v=abc123', 'kind' => 'video']);
        $app = $this->link($p, $m['h']);

        $this->assertSame(['link', 'video', 'https://www.youtube.com/watch?v=abc123'], [$web['source'], $web['kind'], $web['url']]);
        $this->assertSame(['link', 'presentation', '/signals/portfolio/deck/scholarclone'], [$app['source'], $app['kind'], $app['url']]);
        $this->assertSame([2, 0, 0, 0], $this->kitCounts(), 'a link has no document, no history and no library link');
    }

    public function test_a_link_with_another_kind_of_address_is_refused(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();

        foreach (['http://example.com/deck', 'javascript:alert(1)', '//evil.example/deck', '/\\evil.example', 'https://', 'https:///nohost', 'ftp://example.com/x', 'example.com/deck', 'data:text/html;base64,AAAA', 'https://example.com/a b', '/deck with space', 'mailto:a@b.co', ''] as $url) {
            $res = $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => $url, 'kind' => 'video'], $m['h'])->assertStatus(422);
            $this->assertNotSame('', $res->json('message'), "address {$url}");
        }
        $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'banana'], $m['h'])->assertStatus(422);
        $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => 'https://example.com'], $m['h'])->assertStatus(422);
        $this->postJson($this->kitUrl($p, '/links'), ['url' => 'https://example.com', 'kind' => 'video'], $m['h'])->assertStatus(422);
        $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => 'https://example.com/' . str_repeat('a', 490), 'kind' => 'video'], $m['h'])->assertStatus(422);
        $this->assertSame(0, DB::table('g2g_product_kit_items')->count());
    }

    // ---- adding from the library ----

    public function test_a_document_the_whole_organization_can_see_is_added_with_a_product_link_and_no_new_document(): void
    {
        $p = $this->kitProduct(self::T);
        $owner = $this->kitManager(self::T, 'Priya Nair');
        $m = $this->kitManager();
        $doc = $this->libraryDocument(self::T, $owner['id'], ['original_file_name' => 'brochure.pdf', 'title' => 'Brochure 2026']);

        $res = $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $doc, 'description' => 'Leave behind'], $m['h'])->assertCreated();

        $this->assertSame(['file', 'document', 'Brochure 2026', 'brochure.pdf', 'Leave behind', $doc], [$res->json('data.source'), $res->json('data.kind'), $res->json('data.title'), $res->json('data.fileName'), $res->json('data.description'), $res->json('data.documentId')]);
        $this->assertSame(1, DB::table('document_library')->count(), 'the library document is reused, not copied');
        $this->assertSame(0, DB::table('document_library_history')->count(), 'a visible document is not touched, so it has no new history');
        $link = DB::table('document_library_links')->first();
        $this->assertSame([$doc, 'PRODUCT', $p, self::T, $m['id']], [(int) $link->document_id, $link->linked_type, (int) $link->linked_id, (int) $link->sub_institute_id, (int) $link->created_by]);
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $doc], $m['h'])->assertStatus(422)->assertJsonPath('code', 'already_in_kit');
    }

    public function test_a_document_that_is_not_shared_with_the_organization_is_refused_unless_its_owner_says_make_shared(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $mine = $this->libraryDocument(self::T, $m['id'], ['visibility' => 'private']);

        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $mine], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_shared');
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $mine, 'makeShared' => false], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_shared');
        $this->assertSame('private', DB::table('document_library')->where('id', $mine)->value('visibility'), 'a refusal changes nothing');
        $this->assertSame([0, 1, 0, 0], $this->kitCounts());

        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $mine, 'makeShared' => true], $m['h'])->assertCreated()->assertJsonPath('data.documentId', $mine);

        $this->assertSame('organization', DB::table('document_library')->where('id', $mine)->value('visibility'));
        $this->assertSame(1, DB::table('document_library_links')->where('document_id', $mine)->where('linked_type', 'PRODUCT')->count());
        $audit = DB::table('document_library_history')->where('document_id', $mine)->first();
        $this->assertSame(['audit', 'updated'], [$audit->entry_type, $audit->action]);
        $this->assertSame(['changed_fields' => ['visibility'], 'via' => 'sales_kit'], json_decode($audit->details, true));
    }

    public function test_make_shared_works_only_for_the_owner_of_the_document(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager(self::T, 'Meera Shah', 4);
        $owner = $this->kitManager(self::T, 'Priya Nair', 4);
        // A colleague's document that is shared with the department only: the caller can see it but does not own it.
        $theirs = $this->libraryDocument(self::T, $owner['id'], ['visibility' => 'department', 'department_id' => 4]);

        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $theirs], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_shared');
        $res = $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $theirs, 'makeShared' => true], $m['h'])->assertStatus(422);
        $this->assertSame('not_shared', $res->json('code'));
        $this->assertStringContainsString('owns', $res->json('message'));

        $this->assertSame('department', DB::table('document_library')->where('id', $theirs)->value('visibility'), 'a non-owner cannot widen who sees a document');
        $this->assertSame([0, 1, 0, 0], $this->kitCounts());
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $theirs, 'makeShared' => true], $owner['h'])->assertCreated();
        $this->assertSame('organization', DB::table('document_library')->where('id', $theirs)->value('visibility'));
    }

    public function test_a_document_the_caller_cannot_see_or_that_has_no_file_or_is_in_another_organization_is_not_added(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $other = $this->kitManager(self::T, 'Priya Nair');
        $foreign = $this->kitManager(self::T2, 'Zed Foreigner');

        $privateOfAnother = $this->libraryDocument(self::T, $other['id'], ['visibility' => 'private']);
        $deleted = $this->libraryDocument(self::T, $other['id'], ['deleted_at' => now()]);
        $otherTenant = $this->libraryDocument(self::T2, $foreign['id']);
        $indexRow = $this->libraryDocument(self::T, $other['id'], ['storage_path' => null, 'source_system' => 'onboarding', 'source_table' => 'x', 'source_id' => 1]);

        foreach ([$privateOfAnother, $deleted, $otherTenant, 999999] as $id) {
            $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $id, 'makeShared' => true], $m['h'])->assertStatus(404);
        }
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $indexRow], $m['h'])->assertStatus(422)->assertJsonPath('code', 'no_file');
        $this->postJson($this->kitUrl($p, '/from-library'), [], $m['h'])->assertStatus(422);
        $this->assertSame(0, DB::table('g2g_product_kit_items')->count());
        $this->assertSame(0, DB::table('document_library_links')->count());
        $this->assertSame('private', DB::table('document_library')->where('id', $privateOfAnother)->value('visibility'));
    }

    public function test_an_administrator_sees_a_private_document_but_still_cannot_share_it_unless_they_own_it(): void
    {
        $p = $this->kitProduct(self::T);
        $admin = $this->kitUser(self::T, ['view', 'add', 'edit', 'delete'], 'administrator', 'Ann Admin');
        $other = $this->kitManager(self::T, 'Priya Nair');
        $theirs = $this->libraryDocument(self::T, $other['id'], ['visibility' => 'private']);

        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $theirs, 'makeShared' => true], $admin['h'])->assertStatus(422)->assertJsonPath('code', 'not_shared');
        $this->assertSame('private', DB::table('document_library')->where('id', $theirs)->value('visibility'));
    }

    public function test_the_library_search_lists_what_the_caller_may_see_newest_first_without_what_is_already_in_the_kit(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $other = $this->kitManager(self::T, 'Priya Nair');
        $foreign = $this->kitManager(self::T2, 'Zed Foreigner');
        $oldest = $this->libraryDocument(self::T, $other['id'], ['title' => 'Pricing 2025', 'original_file_name' => 'pricing25.pdf', 'created_at' => '2026-01-01 10:00:00']);
        $newest = $this->libraryDocument(self::T, $m['id'], ['title' => 'Pricing 2026', 'original_file_name' => 'pricing26.pdf', 'visibility' => 'private', 'created_at' => '2026-06-01 10:00:00']);
        $inKit = $this->libraryDocument(self::T, $other['id'], ['title' => 'Pricing in the kit', 'original_file_name' => 'inkit.pdf']);
        $this->libraryDocument(self::T, $other['id'], ['title' => 'Pricing private of another', 'visibility' => 'private']);
        $this->libraryDocument(self::T, $other['id'], ['title' => 'Pricing deleted', 'deleted_at' => now()]);
        $this->libraryDocument(self::T2, $foreign['id'], ['title' => 'Pricing of another organization']);
        $this->libraryDocument(self::T, $other['id'], ['title' => 'Pricing without a file', 'storage_path' => null]);
        $this->libraryDocument(self::T, $other['id'], ['title' => 'Unrelated', 'original_file_name' => 'misc.pdf']);
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $inKit], $m['h'])->assertCreated();

        $res = $this->getJson($this->kitUrl($p, '/library-search?q=pricing'), $m['h'])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame([$newest, $oldest], array_column($res->json('data'), 'id'));
        $this->assertSame(['id', 'title', 'fileName', 'mimeType', 'size', 'visibility', 'ownedByMe'], array_keys($res->json('data.0')));
        $this->assertSame([true, false], array_column($res->json('data'), 'ownedByMe'));
        $this->assertSame(['private', 'organization'], array_column($res->json('data'), 'visibility'));
        $this->assertSame('pricing26.pdf', $res->json('data.0.fileName'));
        // The file name matches too, and a search sees nothing of another organization.
        $this->assertSame([$oldest], array_column($this->getJson($this->kitUrl($p, '/library-search?q=pricing25'), $m['h'])->json('data'), 'id'));
        $this->assertSame([], $this->getJson($this->kitUrl($p, '/library-search?q=nothing-like-this'), $m['h'])->json('data'));
    }

    public function test_the_library_search_needs_two_characters_and_returns_at_most_twenty(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        for ($i = 0; $i < 25; $i++) {
            $this->libraryDocument(self::T, $m['id'], ['title' => "Report {$i}", 'original_file_name' => "report{$i}.pdf"]);
        }

        $this->getJson($this->kitUrl($p, '/library-search?q=r'), $m['h'])->assertStatus(422);
        $this->getJson($this->kitUrl($p, '/library-search'), $m['h'])->assertStatus(422);
        $this->getJson($this->kitUrl($p, '/library-search?q=%20r%20'), $m['h'])->assertStatus(422);
        $this->assertCount(20, $this->getJson($this->kitUrl($p, '/library-search?q=report'), $m['h'])->assertOk()->json('data'));
    }

    // ---- changing and removing ----

    public function test_an_item_can_be_renamed_described_reordered_and_given_another_kind_and_the_list_follows_the_order(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $first = $this->uploaded($p, $m['h'], 'one.pdf');
        $second = $this->uploaded($p, $m['h'], 'two.pdf');
        $link = $this->link($p, $m['h']);

        $res = $this->patchJson($this->kitUrl($p, "/{$first['id']}"), ['title' => ' Pricing sheet ', 'description' => 'What we charge', 'kind' => 'presentation', 'sortOrder' => -5], $m['h'])->assertOk();

        $this->assertSame(['Pricing sheet', 'What we charge', 'presentation', -5], [$res->json('data.title'), $res->json('data.description'), $res->json('data.kind'), $res->json('data.sortOrder')]);
        $this->assertSame($first['id'], $res->json('data.id'));
        $this->assertSame([$first['id'], $link['id'], $second['id']], array_column($this->getJson($this->kitUrl($p), $m['h'])->json('data.items'), 'id'), 'sort order first, then the newest');
        $this->patchJson($this->kitUrl($p, "/{$first['id']}"), ['description' => ''], $m['h'])->assertOk()->assertJsonPath('data.description', null);
        $this->patchJson($this->kitUrl($p, "/{$first['id']}"), [], $m['h'])->assertOk()->assertJsonPath('data.title', 'Pricing sheet');
        $this->assertSame($m['id'], (int) DB::table('g2g_product_kit_items')->where('id', $first['id'])->value('updated_by'));
    }

    public function test_the_kind_of_a_file_item_keeps_the_upload_rule_and_a_link_item_may_be_any_kind(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $clip = $this->uploaded($p, $m['h'], 'clip.mp4');
        $docx = $this->uploaded($p, $m['h'], 'a.docx');
        $link = $this->link($p, $m['h']);

        $this->patchJson($this->kitUrl($p, "/{$clip['id']}"), ['kind' => 'document'], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$docx['id']}"), ['kind' => 'presentation'], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['kind' => 'video'], $m['h'])->assertOk()->assertJsonPath('data.kind', 'video');
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['kind' => 'banana'], $m['h'])->assertStatus(422);
        $this->assertSame('video', DB::table('g2g_product_kit_items')->where('id', $clip['id'])->value('kind'));
    }

    public function test_the_address_can_be_changed_for_a_link_item_only_and_keeps_the_address_rules(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $file = $this->uploaded($p, $m['h'], 'a.pdf');
        $link = $this->link($p, $m['h']);

        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['url' => 'https://example.com/new'], $m['h'])->assertOk()->assertJsonPath('data.url', 'https://example.com/new');
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['url' => 'http://example.com/new'], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['url' => '//evil.example'], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['url' => null], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$file['id']}"), ['url' => 'https://example.com'], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['title' => ''], $m['h'])->assertStatus(422);
        $this->patchJson($this->kitUrl($p, "/{$link['id']}"), ['sortOrder' => 'first'], $m['h'])->assertStatus(422);
        $this->assertSame('https://example.com/new', DB::table('g2g_product_kit_items')->where('id', $link['id'])->value('url'));
        $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $file['id'])->value('url'));
    }

    public function test_taking_an_item_out_soft_deletes_the_kit_row_and_removes_its_link_but_the_library_document_stays(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $file = $this->uploaded($p, $m['h'], 'deck.pptx');
        $link = $this->link($p, $m['h']);
        $path = DB::table('document_library')->where('id', $file['documentId'])->value('storage_path');

        $this->deleteJson($this->kitUrl($p, "/{$file['id']}"), [], $m['h'])->assertOk()->assertJsonPath('data.id', $file['id']);

        $row = DB::table('g2g_product_kit_items')->where('id', $file['id'])->first();
        $this->assertNotNull($row->deleted_at, 'soft delete: the row is kept');
        $this->assertSame(0, DB::table('document_library_links')->where('document_id', $file['documentId'])->count(), 'its library link is gone');
        $this->assertNull(DB::table('document_library')->where('id', $file['documentId'])->value('deleted_at'), 'the document stays in the library');
        Storage::disk(config('documents.disk'))->assertExists($path);
        $res = $this->getJson($this->kitUrl($p), $m['h'])->assertOk();
        $this->assertSame([$link['id']], array_column($res->json('data.items'), 'id'));
        $this->assertSame(1, $res->json('data.counts.all'));
        $this->assertSame(1, $res->json('data.counts.presentation'), 'only the link is left, and it is a presentation');
        $this->deleteJson($this->kitUrl($p, "/{$file['id']}"), [], $m['h'])->assertStatus(404);
        $this->patchJson($this->kitUrl($p, "/{$file['id']}"), ['title' => 'x'], $m['h'])->assertStatus(404);

        // The document can be put back from the library, and a link item has no library link to remove.
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $file['documentId']], $m['h'])->assertCreated();
        $this->assertSame(1, DB::table('document_library_links')->where('document_id', $file['documentId'])->count());
        $this->deleteJson($this->kitUrl($p, "/{$link['id']}"), [], $m['h'])->assertOk();
        $this->assertSame(1, DB::table('document_library_links')->count());
    }

    public function test_a_kit_row_whose_document_was_deleted_from_the_library_comes_back_missing_instead_of_failing_the_list(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $gone = $this->uploaded($p, $m['h'], 'gone.pdf');
        $kept = $this->uploaded($p, $m['h'], 'kept.pdf');
        DB::table('document_library')->where('id', $gone['documentId'])->update(['deleted_at' => now()]);

        $res = $this->getJson($this->kitUrl($p), $m['h'])->assertOk();

        $byId = array_column($res->json('data.items'), null, 'id');
        $this->assertTrue($byId[$gone['id']]['missing']);
        $this->assertSame([null, null, null, 'file', $gone['documentId']], [$byId[$gone['id']]['fileName'], $byId[$gone['id']]['mimeType'], $byId[$gone['id']]['size'], $byId[$gone['id']]['source'], $byId[$gone['id']]['documentId']]);
        $this->assertSame('gone', $byId[$gone['id']]['title'], 'the kit keeps its own title');
        $this->assertFalse($byId[$kept['id']]['missing']);
        $this->assertSame('kept.pdf', $byId[$kept['id']]['fileName']);
        $this->assertSame(2, $res->json('data.counts.all'));
        $this->getJson($this->kitUrl($p, "/{$gone['id']}/download"), $m['h'])->assertStatus(404);
        // It can still be taken out of the kit.
        $this->deleteJson($this->kitUrl($p, "/{$gone['id']}"), [], $m['h'])->assertOk();
    }

    public function test_the_counts_say_how_many_of_each_kind_and_never_count_a_removed_item(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $this->uploaded($p, $m['h'], 'a.pptx');
        $this->uploaded($p, $m['h'], 'b.ppt');
        $this->uploaded($p, $m['h'], 'c.pdf');
        $this->uploaded($p, $m['h'], 'd.mp4');
        $this->uploaded($p, $m['h'], 'e.png');
        $this->uploaded($p, $m['h'], 'f.png');
        $this->link($p, $m['h'], ['kind' => 'video', 'url' => 'https://example.com/v']);
        $gone = $this->uploaded($p, $m['h'], 'g.png');
        $this->deleteJson($this->kitUrl($p, "/{$gone['id']}"), [], $m['h'])->assertOk();

        $this->assertSame(['all' => 7, 'presentation' => 2, 'document' => 1, 'video' => 2, 'image' => 2], $this->getJson($this->kitUrl($p), $m['h'])->json('data.counts'));
    }

    // ---- the file itself ----

    public function test_a_file_item_downloads_its_bytes_with_the_rows_content_type_and_a_download_or_inline_disposition(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $viewer = $this->kitUser(self::T, ['view'], 'employee', 'Vik Rao');
        $item = $this->uploaded($p, $m['h'], 'deck.pdf', [], UploadedFile::fake()->createWithContent('deck.pdf', '%PDF-1.4 the bytes of the sales deck'));

        $res = $this->get($this->kitUrl($p, "/{$item['id']}/download"), $viewer['h'])->assertOk();

        $this->assertSame('%PDF-1.4 the bytes of the sales deck', $res->streamedContent());
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('deck.pdf', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $audit = DB::table('document_library_history')->where('document_id', $item['documentId'])->where('entry_type', 'audit')->get();
        $this->assertCount(1, $audit, 'a download is recorded in the library\'s own history');
        $this->assertSame(['downloaded', $viewer['id']], [$audit[0]->action, (int) $audit[0]->created_by]);

        $inline = $this->get($this->kitUrl($p, "/{$item['id']}/download?inline=1"), $viewer['h'])->assertOk();
        $this->assertStringContainsString('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 the bytes of the sales deck', $inline->streamedContent());
        $this->assertSame(1, DB::table('document_library_history')->where('document_id', $item['documentId'])->where('entry_type', 'audit')->count(), 'a preview is not logged as a download');
    }

    public function test_a_link_item_has_no_file_and_a_file_that_is_gone_from_storage_is_a_404(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $link = $this->link($p, $m['h']);
        $file = $this->uploaded($p, $m['h'], 'deck.pdf');

        $this->getJson($this->kitUrl($p, "/{$link['id']}/download"), $m['h'])->assertStatus(404);
        Storage::disk(config('documents.disk'))->delete(DB::table('document_library')->where('id', $file['documentId'])->value('storage_path'));
        $this->getJson($this->kitUrl($p, "/{$file['id']}/download"), $m['h'])->assertStatus(404);
        $this->getJson($this->kitUrl($p, '/999999/download'), $m['h'])->assertStatus(404);
    }

    // ---- products, organizations and what has no kit ----

    public function test_a_kit_is_one_organizations_own_and_another_organization_gets_a_404_on_every_route(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $stranger = $this->kitManager(self::T2, 'Zed Foreigner');
        $item = $this->uploaded($p, $m['h'], 'deck.pdf');
        $doc = $this->libraryDocument(self::T2, $stranger['id']);
        $before = $this->kitCounts();

        $this->getJson($this->kitUrl($p), $stranger['h'])->assertStatus(404);
        $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('x.pdf', 'application/pdf')], $stranger['h'] + ['Accept' => 'application/json'])->assertStatus(404);
        $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], $stranger['h'])->assertStatus(404);
        $this->postJson($this->kitUrl($p, '/from-library'), ['documentId' => $doc], $stranger['h'])->assertStatus(404);
        $this->getJson($this->kitUrl($p, '/library-search?q=deck'), $stranger['h'])->assertStatus(404);
        $this->patchJson($this->kitUrl($p, "/{$item['id']}"), ['title' => 'hijacked'], $stranger['h'])->assertStatus(404);
        $this->deleteJson($this->kitUrl($p, "/{$item['id']}"), [], $stranger['h'])->assertStatus(404);
        $this->getJson($this->kitUrl($p, "/{$item['id']}/download"), $stranger['h'])->assertStatus(404);

        $this->assertSame($before, $this->kitCounts());
        $this->assertSame('deck', DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('title'));
    }

    public function test_an_item_is_found_only_under_its_own_product_and_a_foreign_item_id_under_your_own_product_is_a_404(): void
    {
        $a = $this->kitProduct(self::T, 'ScholarClone');
        $b = $this->kitProduct(self::T, 'Enterprise Brain');
        $theirProduct = $this->kitProduct(self::T2, 'Their product');
        $m = $this->kitManager();
        $stranger = $this->kitManager(self::T2, 'Zed Foreigner');
        $mine = $this->uploaded($a, $m['h'], 'deck.pdf');
        $theirs = $this->uploaded($theirProduct, $stranger['h'], 'theirs.pdf');

        $this->patchJson($this->kitUrl($b, "/{$mine['id']}"), ['title' => 'x'], $m['h'])->assertStatus(404);
        $this->deleteJson($this->kitUrl($b, "/{$mine['id']}"), [], $m['h'])->assertStatus(404);
        $this->getJson($this->kitUrl($b, "/{$mine['id']}/download"), $m['h'])->assertStatus(404);
        // The id of another organization's item on a product BOTH organizations may open (a shared one): only the organization filter keeps it out of reach.
        $shared = $this->kitProduct(0, 'Fiber Valley');
        $theirsOnShared = $this->uploaded($shared, $stranger['h'], 'theirs-shared.pdf');
        $this->patchJson($this->kitUrl($shared, "/{$theirsOnShared['id']}"), ['title' => 'x'], $m['h'])->assertStatus(404);
        $this->deleteJson($this->kitUrl($shared, "/{$theirsOnShared['id']}"), [], $m['h'])->assertStatus(404);
        $this->getJson($this->kitUrl($shared, "/{$theirsOnShared['id']}/download"), $m['h'])->assertStatus(404);
        $this->getJson($this->kitUrl($shared), $m['h'])->assertOk()->assertJsonPath('data.counts.all', 0);
        $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $theirsOnShared['id'])->value('deleted_at'));
        $this->assertSame('theirs-shared', DB::table('g2g_product_kit_items')->where('id', $theirsOnShared['id'])->value('title'));
        $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $theirs['id'])->value('deleted_at'));
    }

    public function test_a_product_shared_by_every_organization_takes_a_separate_kit_for_each_one(): void
    {
        $shared = $this->kitProduct(0, 'Fiber Valley');
        $six = $this->kitManager(self::T);
        $seven = $this->kitManager(self::T2, 'Zed Foreigner');

        $sixItem = $this->uploaded($shared, $six['h'], 'six.pdf');
        $sevenItem = $this->link($shared, $seven['h'], ['title' => 'Seven only']);

        $this->assertSame([$sixItem['id']], array_column($this->getJson($this->kitUrl($shared), $six['h'])->assertOk()->json('data.items'), 'id'));
        $this->assertSame([$sevenItem['id']], array_column($this->getJson($this->kitUrl($shared), $seven['h'])->assertOk()->json('data.items'), 'id'));
        $this->assertSame([self::T, self::T2], [(int) DB::table('g2g_product_kit_items')->where('id', $sixItem['id'])->value('sub_institute_id'), (int) DB::table('g2g_product_kit_items')->where('id', $sevenItem['id'])->value('sub_institute_id')]);
        $this->assertSame(1, $this->getJson($this->kitUrl($shared), $six['h'])->json('data.counts.all'));
        // The link row of the library carries the organization that filed it, not the product's.
        $this->assertSame(self::T, (int) DB::table('document_library_links')->where('linked_type', 'PRODUCT')->where('linked_id', $shared)->value('sub_institute_id'));
        // Six cannot touch seven's item through the shared product.
        $this->patchJson($this->kitUrl($shared, "/{$sevenItem['id']}"), ['title' => 'x'], $six['h'])->assertStatus(404);
        $this->deleteJson($this->kitUrl($shared, "/{$sevenItem['id']}"), [], $six['h'])->assertStatus(404);
        $this->assertSame('Seven only', DB::table('g2g_product_kit_items')->where('id', $sevenItem['id'])->value('title'));
    }

    public function test_only_a_product_has_a_kit_a_sub_product_or_an_unknown_id_has_none(): void
    {
        $p = $this->kitProduct(self::T);
        $sub = $this->kitProduct(self::T, 'Scholar One', 'sub_product', $p);
        $item = $this->kitProduct(self::T, 'A service', 'item');
        $theirSub = $this->kitProduct(self::T2, 'Their sub-product', 'sub_product');
        $m = $this->kitManager();
        $before = $this->kitCounts();

        foreach ([$sub, $item] as $id) {
            $this->getJson($this->kitUrl($id), $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_a_product');
            $this->post($this->kitUrl($id, '/files'), ['file' => $this->upload('x.pdf', 'application/pdf')], $m['h'] + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'not_a_product');
            $this->postJson($this->kitUrl($id, '/links'), ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_a_product');
            $this->postJson($this->kitUrl($id, '/from-library'), ['documentId' => 1], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_a_product');
            $this->getJson($this->kitUrl($id, '/library-search?q=deck'), $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_a_product');
        }
        $this->assertSame($before, $this->kitCounts());
        // Another organization's sub-product is simply not found: it does not even say what kind it is.
        $this->getJson($this->kitUrl($theirSub), $m['h'])->assertStatus(404);
        $this->getJson($this->kitUrl(999999), $m['h'])->assertStatus(404)->assertJsonPath('status', 0);
    }

    // ---- rights ----

    /** @return list<array{string, string, array<string, mixed>, string}> method, path tail, body, the right the route asks for */
    private function everyRoute(): array
    {
        return [
            ['GET', '', [], 'view'], ['GET', '/library-search?q=deck', [], 'add'], ['POST', '/files', [], 'add'], ['POST', '/links', ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], 'add'],
            ['POST', '/from-library', ['documentId' => 1], 'add'], ['PATCH', '/1', ['title' => 'x'], 'edit'], ['DELETE', '/1', [], 'delete'], ['GET', '/1/download', [], 'view'],
        ];
    }

    public function test_a_profile_with_no_row_on_the_sales_kit_gets_a_403_on_every_route_even_an_administrator(): void
    {
        $p = $this->kitProduct(self::T);
        $nobody = $this->kitUser(self::T, [], 'employee', 'No Rights', null, [], false);
        $admin = $this->kitUser(self::T, [], 'administrator', 'Ann Admin', null, [], false);
        $emptyRow = $this->kitUser(self::T, [], 'employee', 'Empty Row');

        foreach ([$nobody, $admin, $emptyRow] as $who) {
            foreach ($this->everyRoute() as [$method, $tail, $body, $right]) {
                $this->json($method, $this->kitUrl($p, $tail), $body, $who['h'])->assertStatus(403);
            }
        }
        foreach ($this->everyRoute() as [$method, $tail, $body]) {
            $this->json($method, $this->kitUrl($p, $tail), $body)->assertStatus(401);
        }
        $this->assertSame([0, 0, 0, 0], $this->kitCounts());
    }

    public function test_each_route_asks_for_its_own_right_and_a_right_on_another_action_opens_nothing_else(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $file = $this->uploaded($p, $m['h'], 'deck.pdf');
        $doc = $this->libraryDocument(self::T, $m['id']);

        foreach (['view', 'add', 'edit', 'delete'] as $only) {
            $who = $this->kitUser(self::T, [$only], 'employee', ucfirst($only) . ' Only');
            foreach ($this->everyRoute() as [$method, $tail, $body, $right]) {
                $tail = str_replace('/1', "/{$file['id']}", $tail);
                $body = $body === ['documentId' => 1] ? ['documentId' => $doc] : $body;
                $status = $this->json($method, $this->kitUrl($p, $tail), $body, $who['h'])->baseResponse->getStatusCode();
                if ($right === $only) {
                    $this->assertNotSame(403, $status, "{$only}-only: {$method} {$tail} is its right");
                } else {
                    $this->assertSame(403, $status, "{$only}-only: {$method} {$tail} asks for {$right}");
                }
            }
        }
        // The delete-only person deleted the item above, which is the one thing that right allows.
        $this->assertNotNull(DB::table('g2g_product_kit_items')->where('id', $file['id'])->value('deleted_at'));
    }

    public function test_a_view_only_viewer_reads_and_downloads_but_changes_nothing(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $viewer = $this->kitUser(self::T, ['view'], 'employee', 'Vik Rao');
        $item = $this->uploaded($p, $m['h'], 'deck.pdf');

        $this->getJson($this->kitUrl($p), $viewer['h'])->assertOk()->assertJsonPath('data.can.add', false)->assertJsonPath('data.can.delete', false);
        $this->get($this->kitUrl($p, "/{$item['id']}/download"), $viewer['h'])->assertOk();
        $before = $this->kitCounts();   // taken after the download, which the library's history records
        $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('x.pdf', 'application/pdf')], $viewer['h'] + ['Accept' => 'application/json'])->assertStatus(403);
        $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], $viewer['h'])->assertStatus(403);
        $this->patchJson($this->kitUrl($p, "/{$item['id']}"), ['title' => 'x'], $viewer['h'])->assertStatus(403);
        $this->deleteJson($this->kitUrl($p, "/{$item['id']}"), [], $viewer['h'])->assertStatus(403);

        $this->assertSame($before, $this->kitCounts());
        $this->assertSame('deck', DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('title'));
        $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('deleted_at'));
    }

    public function test_an_individual_allow_never_widens_a_group_deny_and_an_individual_deny_always_wins(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->uploaded($p, $m['h'], 'deck.pdf');
        // The group row gives view only, the individual columns allow add, edit and delete: the group matrix is the upper bound (MenuRight).
        $widened = $this->kitUser(self::T, ['view'], 'employee', 'Wide Wanda', null, ['add' => 'allow', 'edit' => 'allow', 'delete' => 'allow']);
        // The group row gives everything, the individual column denies view.
        $denied = $this->kitUser(self::T, ['view', 'add', 'edit', 'delete'], 'employee', 'Denied Dev', null, ['view' => 'deny']);

        $this->postJson($this->kitUrl($p, '/links'), ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], $widened['h'])->assertStatus(403);
        $this->patchJson($this->kitUrl($p, "/{$item['id']}"), ['title' => 'x'], $widened['h'])->assertStatus(403);
        $this->deleteJson($this->kitUrl($p, "/{$item['id']}"), [], $widened['h'])->assertStatus(403);
        $this->getJson($this->kitUrl($p), $widened['h'])->assertOk();
        $this->getJson($this->kitUrl($p), $denied['h'])->assertStatus(403);
        $this->patchJson($this->kitUrl($p, "/{$item['id']}"), ['title' => 'renamed'], $denied['h'])->assertOk();
        $this->assertSame('renamed', DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('title'));
    }

    public function test_rights_of_another_menu_row_open_nothing_here(): void
    {
        $p = $this->kitProduct(self::T);
        $who = $this->kitUser(self::T, [], 'administrator', 'Ann Admin', null, [], false);
        // Every right on the Product Portfolio row and on its parent, none on the Sales Kit row.
        foreach ([479, 476] as $menu) {
            DB::table('tblgroupwise_rights_g2g')->insert(['menu_id' => $menu, 'profile_id' => $who['profile'], 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1, 'sub_institute_id' => (string) self::T]);
        }

        foreach ($this->everyRoute() as [$method, $tail, $body]) {
            $this->json($method, $this->kitUrl($p, $tail), $body, $who['h'])->assertStatus(403);
        }
    }

    public function test_a_host_without_the_sales_kit_menu_row_refuses_everybody_and_shows_no_kit_rights(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->kitMenuId())->delete();
        DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->delete();
        \Illuminate\Support\Facades\Cache::flush();

        foreach ($this->everyRoute() as [$method, $tail, $body]) {
            $this->json($method, $this->kitUrl($p, $tail), $body, $m['h'])->assertStatus(403);
        }
    }

    // ---- a host that has not had the database change ----

    public function test_a_host_without_the_kit_table_answers_503_not_set_up_with_a_sentence_on_every_route(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        Schema::drop('g2g_product_kit_items');

        foreach ($this->everyRoute() as [$method, $tail, $body]) {
            $res = $this->json($method, $this->kitUrl($p, $tail), $body, $m['h'])->assertStatus(503);
            $this->assertSame([0, 'not_set_up'], [$res->json('status'), $res->json('code')], "{$method} {$tail}");
            $this->assertStringContainsString('not set up', $res->json('message'));
        }
    }

    public function test_a_host_without_the_library_link_table_is_not_set_up_either_and_nothing_is_written(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        Schema::drop('document_library_links');

        $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('x.pdf', 'application/pdf')], $m['h'] + ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('code', 'not_set_up');
        $this->assertSame(0, DB::table('document_library')->count());
        $this->assertSame([], Storage::disk(config('documents.disk'))->allFiles());
    }

    public function test_a_failed_write_leaves_neither_a_row_nor_a_stored_file(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        // The kit table is the last thing written; make that write fail after the document, its history and its link are in.
        Schema::table('g2g_product_kit_items', fn ($t) => $t->dropColumn('url'));

        $this->post($this->kitUrl($p, '/files'), ['file' => $this->upload('x.pdf', 'application/pdf')], $m['h'] + ['Accept' => 'application/json'])->assertStatus(500);

        $this->assertSame([0, 0, 0, 0], $this->kitCounts(), 'one transaction: the document, its history and its link were taken back');
        $this->assertSame([], Storage::disk(config('documents.disk'))->allFiles(), 'and the stored object was removed');
    }

    // ---- meta ----

    public function test_the_meta_answer_carries_the_four_kit_rights_of_the_caller(): void
    {
        $manager = $this->kitManager();
        $viewer = $this->kitUser(self::T, ['view'], 'employee', 'Vik Rao');
        $nobody = $this->kitUser(self::T, [], 'employee', 'No Rights', null, [], false);

        $can = fn (array $who) => $this->getJson('/api/product-master/meta', $who['h'])->assertOk()->json('data.can');

        $this->assertSame([true, true, true, true], [$can($manager)['kitView'], $can($manager)['kitAdd'], $can($manager)['kitEdit'], $can($manager)['kitDelete']]);
        $this->assertSame([true, false, false, false], array_values(array_intersect_key($can($viewer), array_flip(['kitView', 'kitAdd', 'kitEdit', 'kitDelete']))));
        $this->assertSame([false, false, false, false], array_values(array_intersect_key($can($nobody), array_flip(['kitView', 'kitAdd', 'kitEdit', 'kitDelete']))));
    }

    public function test_the_meta_kit_rights_are_false_when_the_row_or_the_table_is_missing(): void
    {
        $manager = $this->kitManager();
        $keys = array_flip(['kitView', 'kitAdd', 'kitEdit', 'kitDelete']);
        $kitRights = fn () => array_values(array_intersect_key($this->getJson('/api/product-master/meta', $manager['h'])->assertOk()->json('data.can'), $keys));

        $this->assertSame([true, true, true, true], $kitRights());
        Schema::drop('g2g_product_kit_items');
        $this->assertSame([false, false, false, false], $kitRights(), 'no table: the tab must not be offered');
        Schema::create('g2g_product_kit_items', fn ($t) => $t->id());
        DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame([false, false, false, false], $kitRights(), 'no menu row');
    }
}
