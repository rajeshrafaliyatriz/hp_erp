<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Kit\KitShares;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsSalesKitWorld;
use Tests\TestCase;

/**
 * Part B of the Sales Kit's second round (Docs/agentic-crm/briefs/SALES-KIT-SHARE-CONTRACT.md): the public share link of one kit item.
 *
 * The manager side, under /api/product-master (ProductKitController):
 *   POST   api/product-master/{id}/kit/{itemId}/share     share       makes the link, or a new one; the edit right
 *   DELETE api/product-master/{id}/kit/{itemId}/share     stopSharing stops it; the edit right
 * The public side, with NO sign-in (ProductKitPublicController):
 *   GET    api/public/sales-kit/{token}                   show        what the link is
 *   GET    api/public/sales-kit/{token}/file              file        the bytes of a file item
 *
 * The tables come from the real migrations on in-memory sqlite (BuildsSalesKitWorld); files go to a faked disk.
 */
class SalesKitShareApiTest extends TestCase
{
    use BuildsSalesKitWorld;

    private const REFUSAL = ['status' => 0, 'message' => 'This link is not available.'];

    private const DECK = '/signals/portfolio/deck/scholarclone';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesKitWorld();
    }

    private function kit(int $product, string $tail = ''): string
    {
        return "/api/product-master/{$product}/kit" . $tail;
    }

    private function fileItem(int $product, array $h, string $name = 'brochure.pdf', string $mime = 'application/pdf', string $bytes = '%PDF-1.4 the public bytes'): array
    {
        return $this->post($this->kit($product, '/files'), ['file' => UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime)], $h + ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    private function linkItem(int $product, array $h, string $url = 'https://example.com/walkthrough', string $kind = 'video', string $title = 'Walkthrough'): array
    {
        return $this->postJson($this->kit($product, '/links'), ['title' => $title, 'url' => $url, 'kind' => $kind], $h)->assertCreated()->json('data');
    }

    /** Makes the link and answers its token. */
    private function share(int $product, int $item, array $h, array $body = []): string
    {
        return (string) $this->postJson($this->kit($product, "/{$item}/share"), $body, $h)->assertSuccessful()->json('data.share.token');
    }

    private function publicUrl(string $token, string $tail = ''): string
    {
        return "/api/public/sales-kit/{$token}" . $tail;
    }

    /** What the answer to a refused public request looks like, for comparing every refusal with every other. @return array{int, string, array<string, string|null>} */
    private function refusal(string $url): array
    {
        $res = $this->getJson($url);

        return [$res->getStatusCode(), $res->getContent(), [
            'cache' => $res->headers->get('Cache-Control'), 'referrer' => $res->headers->get('Referrer-Policy'), 'robots' => $res->headers->get('X-Robots-Tag'), 'nosniff' => $res->headers->get('X-Content-Type-Options'),
            'type' => $res->headers->get('Content-Type'),
        ]];
    }

    // ---- making, replacing and stopping a link ----

    public function test_a_manager_makes_a_link_that_never_expires_by_default_and_the_item_shows_it(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $this->assertNull($item['share'], 'a new item is not shared');

        $res = $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertCreated();

        $share = $res->json('data.share');
        $this->assertSame(['token', 'expiresAt', 'sharedAt', 'expired'], array_keys($share));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $share['token']);
        $this->assertFalse($share['expired']);
        $this->assertNull($share['expiresAt'], 'no days given: the link does not expire (the owner asked for links that do not expire)');
        $this->assertEqualsWithDelta(now()->getTimestamp(), Carbon::parse($share['sharedAt'])->getTimestamp(), 5);
        $this->assertSame($item['id'], $res->json('data.id'), 'the answer is the item, with its share');
        $row = DB::table('g2g_product_kit_items')->where('id', $item['id'])->first();
        $this->assertSame([$share['token'], $m['id']], [$row->share_token, (int) $row->shared_by]);
        // The list shows it too.
        $this->assertSame($share, $this->getJson($this->kit($p), $m['h'])->assertOk()->json('data.items.0.share'));
    }

    public function test_the_days_a_link_works_for_are_7_30_90_365_or_no_expiry_and_nothing_else(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);

        foreach ([7, 30, 90, 365] as $days) {
            $expires = $this->postJson($this->kit($p, "/{$item['id']}/share"), ['days' => $days], $m['h'])->assertSuccessful()->json('data.share.expiresAt');
            $this->assertEqualsWithDelta(now()->addDays($days)->getTimestamp(), Carbon::parse($expires)->getTimestamp(), 5, "{$days} days");
        }
        $never = $this->postJson($this->kit($p, "/{$item['id']}/share"), ['days' => 0], $m['h'])->assertSuccessful();
        $this->assertNull($never->json('data.share.expiresAt'), 'zero is no expiry');
        $this->assertFalse($never->json('data.share.expired'));
        $token = $never->json('data.share.token');

        foreach ([5, 31, -1, 'soon', 1.5, [30], true] as $bad) {
            $this->postJson($this->kit($p, "/{$item['id']}/share"), ['days' => $bad], $m['h'])->assertStatus(422);
        }
        $this->assertSame($token, DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('share_token'), 'a refused request changes nothing');
    }

    public function test_making_a_link_again_replaces_it_and_the_old_one_stops_at_once(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $first = $this->share($p, $item['id'], $m['h']);
        $this->getJson($this->publicUrl($first))->assertOk();

        $res = $this->postJson($this->kit($p, "/{$item['id']}/share"), ['days' => 7], $m['h'])->assertOk();   // 200: a link already existed

        $second = $res->json('data.share.token');
        $this->assertNotSame($first, $second);
        $this->assertSame(self::REFUSAL, $this->getJson($this->publicUrl($first))->assertStatus(404)->json(), 'the old link is gone');
        $this->getJson($this->publicUrl($first, '/file'))->assertStatus(404);
        $this->getJson($this->publicUrl($second))->assertOk();
        $this->assertSame(1, DB::table('g2g_product_kit_items')->whereNotNull('share_token')->count());
    }

    public function test_stopping_a_link_ends_it_and_doing_it_again_or_on_an_item_never_shared_changes_nothing(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $other = $this->fileItem($p, $m['h'], 'other.pdf');
        $otherToken = $this->share($p, $other['id'], $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);

        $res = $this->deleteJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertOk();

        $this->assertNull($res->json('data.share'));
        $row = DB::table('g2g_product_kit_items')->where('id', $item['id'])->first();
        $this->assertSame([null, null, null, null], [$row->share_token, $row->share_expires_at, $row->shared_at, $row->shared_by]);
        $this->getJson($this->publicUrl($token))->assertStatus(404);
        $this->deleteJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertOk()->assertJsonPath('data.share', null);
        $this->deleteJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertOk();
        $this->getJson($this->publicUrl($otherToken))->assertOk();   // another item's link is not touched
        $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertCreated();   // and it can be shared again
    }

    public function test_editing_an_item_never_changes_its_token_and_a_shared_deck_stays_shared(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->linkItem($p, $m['h'], self::DECK, 'presentation', 'Deck');
        $token = $this->share($p, $item['id'], $m['h']);

        $this->patchJson($this->kit($p, "/{$item['id']}"), ['title' => 'New title', 'description' => 'New words', 'kind' => 'document', 'sortOrder' => 3], $m['h'])->assertOk();
        $this->assertSame($token, $this->getJson($this->kit($p), $m['h'])->json('data.items.0.share.token'));
        $this->assertSame('New title', $this->getJson($this->publicUrl($token))->assertOk()->json('data.title'), 'the public page follows the item');
        $this->patchJson($this->kit($p, "/{$item['id']}"), ['url' => 'https://example.com/other-deck'], $m['h'])->assertOk();
        $this->assertSame($token, DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('share_token'), 'an https address is still shareable');
    }

    public function test_changing_a_shared_link_item_to_a_page_inside_the_app_stops_its_link(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->linkItem($p, $m['h'], self::DECK, 'presentation', 'Deck');
        $token = $this->share($p, $item['id'], $m['h']);

        $this->patchJson($this->kit($p, "/{$item['id']}"), ['url' => '/portfolio/sales-kit'], $m['h'])->assertOk()->assertJsonPath('data.share', null);

        $this->getJson($this->publicUrl($token))->assertStatus(404);
        $row = DB::table('g2g_product_kit_items')->where('id', $item['id'])->first();
        $this->assertSame([null, null, null, null], [$row->share_token, $row->share_expires_at, $row->shared_at, $row->shared_by]);
        $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_shareable');
    }

    public function test_taking_an_item_out_of_the_kit_ends_its_link(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);
        $this->getJson($this->publicUrl($token))->assertOk();

        $this->deleteJson($this->kit($p, "/{$item['id']}"), [], $m['h'])->assertOk();

        $this->assertSame(self::REFUSAL, $this->getJson($this->publicUrl($token))->assertStatus(404)->json());
        $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('share_token'));
        $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertStatus(404);
    }

    public function test_only_a_file_an_https_link_or_a_deck_can_be_shared_and_a_page_inside_the_app_or_a_missing_file_cannot(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $file = $this->fileItem($p, $m['h']);
        $web = $this->linkItem($p, $m['h']);
        $deck = $this->linkItem($p, $m['h'], self::DECK, 'presentation', 'Deck');
        $gone = $this->fileItem($p, $m['h'], 'gone.pdf');
        DB::table('document_library')->where('id', $gone['documentId'])->update(['deleted_at' => now()]);
        $page = $this->linkItem($p, $m['h'], '/portfolio/sales-kit', 'document', 'A page of the app');
        $otherPage = $this->linkItem($p, $m['h'], '/signals/portfolio/deck/scholarclone/extra', 'document', 'Not a deck path');
        $queryDeck = $this->linkItem($p, $m['h'], '/signals/portfolio/deck/scholarclone?mode=short', 'document', 'A deck with a query');

        foreach ([$file, $web, $deck] as $item) {
            $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertCreated();
        }
        foreach ([$page, $otherPage, $queryDeck] as $item) {
            $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_shareable');
            $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('share_token'));
        }
        $this->postJson($this->kit($p, "/{$gone['id']}/share"), [], $m['h'])->assertStatus(422)->assertJsonPath('code', 'missing_file');
        $this->assertNull(DB::table('g2g_product_kit_items')->where('id', $gone['id'])->value('share_token'));
    }

    public function test_a_link_is_one_organizations_own_another_organization_gets_a_404_to_make_or_stop_it(): void
    {
        $shared = $this->kitProduct(0, 'Fiber Valley');
        $p = $this->kitProduct(self::T);
        $six = $this->kitManager(self::T);
        $seven = $this->kitManager(self::T2, 'Zed Foreigner');
        $mine = $this->fileItem($p, $six['h']);
        $mineOnShared = $this->fileItem($shared, $six['h'], 'six.pdf');
        $token = $this->share($shared, $mineOnShared['id'], $six['h']);

        $this->postJson($this->kit($p, "/{$mine['id']}/share"), [], $seven['h'])->assertStatus(404);                       // a product of another organization
        $this->postJson($this->kit($shared, "/{$mineOnShared['id']}/share"), [], $seven['h'])->assertStatus(404);          // a product both may open, an item of the other
        $this->deleteJson($this->kit($shared, "/{$mineOnShared['id']}/share"), [], $seven['h'])->assertStatus(404);
        $this->postJson($this->kit($shared, "/{$mine['id']}/share"), [], $six['h'])->assertStatus(404);                    // my item under another product
        $this->assertSame($token, DB::table('g2g_product_kit_items')->where('id', $mineOnShared['id'])->value('share_token'), 'the link is untouched');
        $this->getJson($this->publicUrl($token))->assertOk();
        // The shared product has a separate kit for each organization, and so a separate set of links.
        $this->assertSame([], array_values(array_filter($this->getJson($this->kit($shared), $seven['h'])->assertOk()->json('data.items'), fn ($i) => ($i['share']['token'] ?? null) === $token)));
    }

    public function test_only_a_product_has_a_kit_to_share_from(): void
    {
        $p = $this->kitProduct(self::T);
        $sub = $this->kitProduct(self::T, 'Scholar One', 'sub_product', $p);
        $m = $this->kitManager();

        $this->postJson($this->kit($sub, '/1/share'), [], $m['h'])->assertStatus(422)->assertJsonPath('code', 'not_a_product');
        $this->postJson($this->kit($p, '/999/share'), [], $m['h'])->assertStatus(404);
        $this->deleteJson($this->kit($p, '/999/share'), [], $m['h'])->assertStatus(404);
    }

    // ---- rights ----

    public function test_making_and_stopping_a_link_ask_for_the_edit_right_and_a_viewer_sees_the_link_but_cannot_change_it(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);
        $viewer = $this->kitUser(self::T, ['view'], 'employee', 'Vik Rao');
        $adder = $this->kitUser(self::T, ['view', 'add', 'delete'], 'employee', 'Ada Add');
        $editor = $this->kitUser(self::T, ['edit'], 'employee', 'Ed Only');
        $nobody = $this->kitUser(self::T, [], 'employee', 'No Rights', null, [], false);
        $admin = $this->kitUser(self::T, [], 'administrator', 'Ann Admin', null, [], false);
        $widened = $this->kitUser(self::T, ['view'], 'employee', 'Wide Wanda', null, ['edit' => 'allow']);

        // Sales staff copy the link to send it: a viewer sees the token in the list, but cannot make or stop one.
        $this->assertSame($token, $this->getJson($this->kit($p), $viewer['h'])->assertOk()->json('data.items.0.share.token'));
        foreach ([$viewer, $adder, $nobody, $admin, $widened] as $who) {
            $this->postJson($this->kit($p, "/{$item['id']}/share"), ['days' => 7], $who['h'])->assertStatus(403);
            $this->deleteJson($this->kit($p, "/{$item['id']}/share"), [], $who['h'])->assertStatus(403);
        }
        $this->postJson($this->kit($p, "/{$item['id']}/share"), [], [])->assertStatus(401);
        $this->assertSame($token, DB::table('g2g_product_kit_items')->where('id', $item['id'])->value('share_token'), 'nothing changed');
        $this->postJson($this->kit($p, "/{$item['id']}/share"), [], $editor['h'])->assertOk();
        $this->deleteJson($this->kit($p, "/{$item['id']}/share"), [], $editor['h'])->assertOk()->assertJsonPath('data.share', null);
    }

    // ---- a host without the share columns ----

    public function test_a_host_without_the_share_columns_keeps_the_kit_working_without_a_share_field_and_refuses_the_share_routes_with_503(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);
        (require base_path('database/migrations/2026_12_30_100000_add_public_share_to_product_kit_items.php'))->down();

        $list = $this->getJson($this->kit($p), $m['h'])->assertOk()->json('data.items.0');
        $this->assertArrayNotHasKey('share', $list, 'no share in the item on a host that cannot share');
        $this->assertSame('brochure', $list['title']);
        $this->patchJson($this->kit($p, "/{$item['id']}"), ['title' => 'Still editable'], $m['h'])->assertOk()->assertJsonMissingPath('data.share');
        $this->linkItem($p, $m['h']);
        $this->deleteJson($this->kit($p, "/{$item['id']}"), [], $m['h'])->assertOk();
        foreach ([['POST', ['days' => 30]], ['DELETE', []]] as [$method, $body]) {
            $res = $this->json($method, $this->kit($p, '/2/share'), $body, $m['h'])->assertStatus(503);
            $this->assertSame([0, 'not_set_up'], [$res->json('status'), $res->json('code')]);
            $this->assertStringContainsString('not set up', $res->json('message'));
        }
        // The public side has nothing to find: the same plain 404.
        $this->assertSame(self::REFUSAL, $this->getJson($this->publicUrl($token))->assertStatus(404)->json());
        $this->getJson($this->publicUrl($token, '/file'))->assertStatus(404);
    }

    // ---- the public side ----

    public function test_the_public_answer_for_a_file_says_only_what_the_page_shows_and_holds_no_private_id(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $package = $this->officeBytes('pptx');
        $item = $this->fileItem($p, $m['h'], 'ScholarClone-Client-Presentation.pptx', self::PPTX, $package);
        $this->patchJson($this->kit($p, "/{$item['id']}"), ['title' => 'What is your school not seeing?', 'description' => 'The client deck'], $m['h'])->assertOk();
        $token = $this->share($p, $item['id'], $m['h'], ['days' => 90]);

        $res = $this->getJson($this->publicUrl($token))->assertOk()->assertJsonPath('status', 1);

        $data = $res->json('data');
        $this->assertSame(['title', 'description', 'kind', 'source', 'productName', 'fileName', 'mimeType', 'size', 'url', 'deck', 'expiresAt'], array_keys($data), 'exactly these keys: no id, no organization, no user');
        $this->assertSame(['What is your school not seeing?', 'The client deck', 'presentation', 'file', 'ScholarClone', 'ScholarClone-Client-Presentation.pptx', self::PPTX, null, null],
            [$data['title'], $data['description'], $data['kind'], $data['source'], $data['productName'], $data['fileName'], $data['mimeType'], $data['url'], $data['deck']]);
        $this->assertSame(strlen($package), $data['size']);
        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), Carbon::parse($data['expiresAt'])->getTimestamp(), 5);
        $this->assertSame(['status', 'data'], array_keys($res->json()), 'and nothing else at the top');
        $json = (string) $res->getContent();
        foreach (['documentId', 'sub_institute', 'createdBy', 'created_by', 'tenant', 'Meera', '"id"'] as $private) {
            $this->assertStringNotContainsString($private, $json, "'{$private}' must not appear");
        }
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('noindex', $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_the_public_answer_for_a_link_gives_the_address_of_an_https_link_and_the_key_of_a_deck(): void
    {
        $p = $this->kitProduct(self::T, 'ScholarClone');
        $m = $this->kitManager();
        $web = $this->linkItem($p, $m['h'], 'https://www.youtube.com/watch?v=abc', 'video', 'Walkthrough');
        $deck = $this->linkItem($p, $m['h'], self::DECK, 'presentation', 'Interactive deck');
        $forever = $this->share($p, $web['id'], $m['h'], ['days' => 0]);
        $deckToken = $this->share($p, $deck['id'], $m['h']);

        $a = $this->getJson($this->publicUrl($forever))->assertOk()->json('data');
        $b = $this->getJson($this->publicUrl($deckToken))->assertOk()->json('data');

        $this->assertSame(['link', 'video', 'https://www.youtube.com/watch?v=abc', null, null, null, null, null, 'ScholarClone'], [$a['source'], $a['kind'], $a['url'], $a['deck'], $a['fileName'], $a['mimeType'], $a['size'], $a['expiresAt'], $a['productName']]);
        $this->assertSame(['link', 'presentation', null, 'scholarclone', 'Interactive deck'], [$b['source'], $b['kind'], $b['url'], $b['deck'], $b['title']], 'a deck gives its key and no address of the app');
        $this->getJson($this->publicUrl($forever, '/file'))->assertStatus(404);   // a link item has no file
    }

    public function test_the_public_file_gives_the_bytes_with_the_rows_type_and_every_safety_header(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h'], 'brochure.pdf', 'application/pdf', '%PDF-1.4 the public bytes');
        $token = $this->share($p, $item['id'], $m['h']);

        $res = $this->get($this->publicUrl($token, '/file'))->assertOk();

        $this->assertSame('%PDF-1.4 the public bytes', $res->streamedContent());
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertSame('noindex, nofollow', $res->headers->get('X-Robots-Tag'));
        $cache = $res->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'), 'a download unless an inline preview is asked for');
        $this->assertStringContainsString('brochure.pdf', $res->headers->get('Content-Disposition'));
    }

    /** @return array<string, array{string, string, string, bool}> file name, type, bytes, may be shown inline */
    public static function inlineCases(): array
    {
        return [
            'pdf' => ['a.pdf', 'application/pdf', '%PDF-1.4', true],
            'png' => ['a.png', 'image/png', "\x89PNG", true],
            'jpeg' => ['a.jpg', 'image/jpeg', "\xFF\xD8", true],
            'webp' => ['a.webp', 'image/webp', 'RIFF', true],
            'gif' => ['a.gif', 'image/gif', 'GIF89a', true],
            'mp4' => ['a.mp4', 'video/mp4', 'mp4', true],
            'webm' => ['a.webm', 'video/webm', 'webm', true],
            'mov' => ['a.mov', 'video/quicktime', 'mov', true],
            'a pptx' => ['a.pptx', 'PPTX', 'x', false],
            'a docx' => ['a.docx', 'DOCX', 'x', false],
            'an xlsx' => ['a.xlsx', 'XLSX', 'x', false],
            'a txt' => ['a.txt', 'text/plain', 'plain words', false],
            'a csv' => ['a.csv', 'text/csv', 'a,b', false],
            'an rtf' => ['a.rtf', 'application/rtf', '{\rtf1}', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inlineCases')]
    public function test_a_file_is_shown_inline_only_when_asked_and_only_if_it_is_a_pdf_an_image_or_a_video(string $name, string $mime, string $bytes, bool $mayBeInline): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $mime = ['PPTX' => self::PPTX, 'DOCX' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$mime] ?? $mime;
        $content = in_array(pathinfo($name, PATHINFO_EXTENSION), ['pptx', 'docx', 'xlsx'], true) ? $this->officeBytes(pathinfo($name, PATHINFO_EXTENSION)) : $bytes;
        $item = $this->fileItem($p, $m['h'], $name, $mime, (string) $content);
        $token = $this->share($p, $item['id'], $m['h']);

        $asked = $this->get($this->publicUrl($token, '/file?inline=1'))->assertOk();
        $plain = $this->get($this->publicUrl($token, '/file'))->assertOk();
        $zero = $this->get($this->publicUrl($token, '/file?inline=0'))->assertOk();

        $this->assertStringContainsString($mayBeInline ? 'inline' : 'attachment', $asked->headers->get('Content-Disposition'), "{$name} with inline=1");
        $this->assertStringContainsString('attachment', $plain->headers->get('Content-Disposition'), 'without inline it is always a download');
        $this->assertStringContainsString('attachment', $zero->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $asked->headers->get('X-Content-Type-Options'));
    }

    public function test_a_download_is_written_to_the_librarys_history_and_an_inline_preview_is_not(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);
        $audit = fn () => DB::table('document_library_history')->where('document_id', $item['documentId'])->where('entry_type', 'audit')->get();

        $this->get($this->publicUrl($token, '/file?inline=1'))->assertOk();
        $this->getJson($this->publicUrl($token))->assertOk();
        $this->assertCount(0, $audit(), 'a preview and a read of the item are not downloads');

        $this->get($this->publicUrl($token, '/file'))->assertOk();

        $rows = $audit();
        $this->assertCount(1, $rows);
        $this->assertSame(['downloaded', ['via' => 'sales_kit_public'], null, '127.0.0.1'], [$rows[0]->action, json_decode($rows[0]->details, true), $rows[0]->created_by, $rows[0]->ip_address]);
    }

    public function test_a_file_that_cannot_be_shown_inline_is_still_a_download_and_is_recorded_as_one(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h'], 'deck.pptx', self::PPTX, $this->officeBytes('pptx'));
        $token = $this->share($p, $item['id'], $m['h']);

        $res = $this->get($this->publicUrl($token, '/file?inline=1'))->assertOk();

        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertSame(1, DB::table('document_library_history')->where('document_id', $item['documentId'])->where('action', 'downloaded')->count());
    }

    public function test_every_refusal_is_the_same_plain_404_so_a_caller_cannot_tell_why(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $unknown = str_repeat('Z', 48);
        $stopped = $this->fileItem($p, $m['h'], 'stopped.pdf');
        $stoppedToken = $this->share($p, $stopped['id'], $m['h']);
        $this->deleteJson($this->kit($p, "/{$stopped['id']}/share"), [], $m['h'])->assertOk();
        $expired = $this->fileItem($p, $m['h'], 'expired.pdf');
        $expiredToken = $this->share($p, $expired['id'], $m['h']);
        DB::table('g2g_product_kit_items')->where('id', $expired['id'])->update(['share_expires_at' => now()->subSecond()]);
        $rotated = $this->fileItem($p, $m['h'], 'rotated.pdf');
        $oldToken = $this->share($p, $rotated['id'], $m['h']);
        $this->share($p, $rotated['id'], $m['h']);
        $removed = $this->fileItem($p, $m['h'], 'removed.pdf');
        $removedToken = $this->share($p, $removed['id'], $m['h']);
        $this->deleteJson($this->kit($p, "/{$removed['id']}"), [], $m['h'])->assertOk();
        $noDoc = $this->fileItem($p, $m['h'], 'nodoc.pdf');
        $noDocToken = $this->share($p, $noDoc['id'], $m['h']);
        DB::table('document_library')->where('id', $noDoc['documentId'])->update(['deleted_at' => now()]);
        $noFile = $this->fileItem($p, $m['h'], 'nofile.pdf');
        $noFileToken = $this->share($p, $noFile['id'], $m['h']);
        Storage::disk(config('documents.disk'))->delete((string) DB::table('document_library')->where('id', $noFile['documentId'])->value('storage_path'));
        $page = $this->linkItem($p, $m['h'], 'https://example.com/ok');
        $pageToken = $this->share($p, $page['id'], $m['h']);
        DB::table('g2g_product_kit_items')->where('id', $page['id'])->update(['url' => '/portfolio/sales-kit']);   // an address that has become one of the app's own pages
        $noProduct = $this->kitProduct(self::T, 'Gone');
        $orphan = $this->fileItem($noProduct, $m['h'], 'orphan.pdf');
        $orphanToken = $this->share($noProduct, $orphan['id'], $m['h']);
        DB::table('g2g_product_master')->where('id', $noProduct)->delete();

        $tokens = [
            'unknown' => $unknown, 'stopped' => $stoppedToken, 'expired' => $expiredToken, 'replaced' => $oldToken, 'item removed' => $removedToken, 'document deleted' => $noDocToken,
            'page of the app' => $pageToken, 'product gone' => $orphanToken, 'too short' => 'abc', 'too long' => str_repeat('a', 49), 'a symbol' => str_repeat('a', 47) . '-', 'a space' => str_repeat('a', 47) . '%20',
        ];
        $first = null;
        foreach ($tokens as $why => $token) {
            foreach (['', '/file', '/file?inline=1'] as $tail) {
                $answer = $this->refusal($this->publicUrl($token, $tail));
                $this->assertSame(404, $answer[0], "{$why}{$tail}");
                $this->assertSame(json_encode(self::REFUSAL), $answer[1], "{$why}{$tail}: the same body");
                $first ??= $answer;
                $this->assertSame($first, $answer, "{$why}{$tail}: the same status, body and headers as every other refusal");
            }
        }
        // A file that is recorded but gone from storage is the same refusal on the file route (the item itself is still described).
        $this->assertSame($first, $this->refusal($this->publicUrl($noFileToken, '/file')));
        $this->assertSame(self::REFUSAL, $this->getJson($this->publicUrl($tokens['unknown']))->json());
    }

    public function test_a_link_with_no_expiry_and_one_that_ends_in_the_future_work_and_the_moment_it_ends_it_stops(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h'], ['days' => 0]);

        $this->getJson($this->publicUrl($token))->assertOk();
        DB::table('g2g_product_kit_items')->where('id', $item['id'])->update(['share_expires_at' => now()->addMinute()]);
        $this->getJson($this->publicUrl($token))->assertOk();
        $this->get($this->publicUrl($token, '/file'))->assertOk();
        DB::table('g2g_product_kit_items')->where('id', $item['id'])->update(['share_expires_at' => now()->subSecond()]);
        $this->getJson($this->publicUrl($token))->assertStatus(404);
        $this->getJson($this->publicUrl($token, '/file'))->assertStatus(404);
        // The manager still sees that it has run out, and can make a new one.
        $this->assertTrue($this->getJson($this->kit($p), $m['h'])->json('data.items.0.share.expired'));
        $new = $this->share($p, $item['id'], $m['h']);
        $this->getJson($this->publicUrl($new))->assertOk();
    }

    public function test_a_token_of_the_wrong_shape_is_refused_before_the_database_is_asked(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);
        $kitQueries = 0;
        DB::listen(function ($query) use (&$kitQueries) {
            if (str_contains($query->sql, 'g2g_product_kit_items')) {
                $kitQueries++;
            }
        });

        foreach (['abc', str_repeat('a', 47), str_repeat('a', 49), str_repeat('a', 47) . '-', str_repeat('a', 47) . '_', str_repeat('é', 24)] as $bad) {
            $this->getJson($this->publicUrl(rawurlencode($bad)))->assertStatus(404);
            $this->getJson($this->publicUrl(rawurlencode($bad), '/file'))->assertStatus(404);
        }
        $this->assertSame(0, $kitQueries, 'no query on the kit table for a token that cannot be one');

        $this->getJson($this->publicUrl(str_repeat('a', 48)))->assertStatus(404);
        $this->assertGreaterThan(0, $kitQueries, 'a well formed token is looked up');
        $this->getJson($this->publicUrl($token))->assertOk();
    }

    public function test_the_token_is_compared_exactly_not_without_regard_to_case(): void
    {
        $p = $this->kitProduct(self::T);
        $m = $this->kitManager();
        $item = $this->fileItem($p, $m['h']);
        $token = $this->share($p, $item['id'], $m['h']);
        $flipped = strtolower($token) === $token ? strtoupper($token) : strtolower($token);
        $this->assertNotSame($token, $flipped);

        $this->getJson($this->publicUrl($token))->assertOk();
        $this->getJson($this->publicUrl($flipped))->assertStatus(404);
        // The database on the real hosts compares text without regard to case, so the lookup is checked again exactly in code.
        $this->assertNull(KitShares::resolve($flipped));
        $this->assertNotNull(KitShares::resolve($token));
    }

    // ---- the routes ----

    /** @return array<string, \Illuminate\Routing\Route> */
    private function routesUnder(string $prefix): array
    {
        $found = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $prefix || str_starts_with($route->uri(), $prefix . '/')) {
                foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                    $found[$method . ' ' . $route->uri()] = $route;
                }
            }
        }
        ksort($found);

        return $found;
    }

    public function test_the_two_public_routes_carry_no_sign_in_and_no_right_and_do_carry_a_throttle(): void
    {
        $routes = $this->routesUnder('api/public/sales-kit');

        $this->assertSame(['GET api/public/sales-kit/{token}', 'GET api/public/sales-kit/{token}/file'], array_keys($routes), 'exactly these two public routes');
        foreach ($routes as $name => $route) {
            $middleware = array_map(fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : 'closure'), $route->gatherMiddleware());
            $this->assertNotContains('api.token', $middleware, "{$name}: no user token");
            $this->assertSame([], array_values(array_filter($middleware, fn ($m) => str_starts_with($m, 'platformright') || str_starts_with($m, 'menuright') || str_starts_with($m, 'auth') || str_starts_with($m, 'profile') || str_starts_with($m, 'subject') || str_contains($m, 'RequireApiToken'))), "{$name}: no sign-in and no right");
            $throttle = array_values(array_filter($middleware, fn ($m) => str_starts_with($m, 'throttle:')));
            $this->assertCount(1, $throttle, "{$name}: one throttle");
        }
        $this->assertStringStartsWith('throttle:60,1', array_values(array_filter($routes['GET api/public/sales-kit/{token}']->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))[0]);
        $this->assertStringStartsWith('throttle:30,1', array_values(array_filter($routes['GET api/public/sales-kit/{token}/file']->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))[0], 'the file is throttled harder than the read');
    }

    public function test_the_throttle_really_stops_a_caller_and_the_two_routes_count_separately(): void
    {
        $unknown = $this->publicUrl(str_repeat('Q', 48));

        for ($i = 0; $i < 30; $i++) {
            $this->getJson($unknown . '/file')->assertStatus(404);
        }
        $this->getJson($unknown . '/file')->assertStatus(429);
        $this->getJson($unknown)->assertStatus(404);   // the read has its own count
        for ($i = 0; $i < 59; $i++) {
            $this->getJson($unknown)->assertStatus(404);
        }
        $this->getJson($unknown)->assertStatus(429);
    }

    public function test_the_two_manage_routes_ask_for_the_edit_right_of_the_sales_kit_row_and_nothing_else(): void
    {
        $routes = $this->routesUnder('api/product-master');

        foreach (['POST api/product-master/{id}/kit/{itemId}/share', 'DELETE api/product-master/{id}/kit/{itemId}/share'] as $name) {
            $this->assertArrayHasKey($name, $routes);
            $middleware = $routes[$name]->gatherMiddleware();
            $this->assertContains('api.token', $middleware);
            $this->assertSame(['platformright:/portfolio/sales-kit,edit'], array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'platformright:'))), $name);
        }
    }
}
