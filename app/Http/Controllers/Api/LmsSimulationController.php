<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsIdentity;
use App\Http\Controllers\Controller;
use App\Services\Lms\SimulationAssetService;
use App\Services\Lms\SimulationException;
use App\Services\Lms\SimulationLimits;
use App\Services\Lms\SimulationStore;
use App\Support\MenuRight;
use App\Support\RoleKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simulation Learning: the gallery, the player's data, and what an administrator may add, change and remove.
 *
 * Everything is scoped to the CALLER'S organisation, which comes from their token and never from the request (see ResolvesLmsIdentity). A
 * simulation belongs to one organisation or is a platform simulation that an organisation switches on; nobody can read, play, change or delete
 * another organisation's. Nothing about which simulations exist is in code: this lists rows.
 *
 * WHO MAY DO WHAT is the rights matrix on the `Simulation Learning` menu row (/module/lms/simulation-learning), the same one every other
 * screen uses and administrators manage in Role & Permissions: `view` to browse and play, `add` to add a simulation (import a specification,
 * or switch a platform one on), `edit` to save a new version or change a card, `delete` to remove, restore or switch one off. The
 * administrator and HR roles pass the same gates by role, exactly as on Assignments (`anyaccess:admin+hr,<link>@<right>`).
 *
 * PROGRESS is what the runtime reports from the page (percent, step, completed). It is recorded as reported and is not a verified score: the
 * server does not replay the learner's choices. It never goes backwards and a completed simulation stays completed.
 *
 * ASSET FILES (3D models and images) are private objects in the project's bucket, registered in `g2g_simulation_asset` with their licence and
 * credit. `show` lists the credits of the assets a specification names; `asset` streams one file, after the same visibility check as `show` and
 * only for a hash the simulation's current specification names (see SimulationStore::assetRows for whose files that can be). `uploadAsset` is how
 * an author adds one of their own (see SimulationAssetService::upload), and `limits` tells the screen what it may send.
 */
class LmsSimulationController extends Controller
{
    use ResolvesLmsIdentity;

    public const MENU_LINK = '/module/lms/simulation-learning';

    public function __construct(private readonly SimulationStore $store, private readonly SimulationAssetService $assets)
    {
    }

    // ---------------------------------------------------------------- reading

    /** GET /lms/simulations?search=&include=all */
    public function index(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $search = $request->query('search');   // `?search[]=x` is an array: not a search, so ignored
        $rows = $this->store->visible($ctx['tenant'], $this->managing($ctx), is_string($search) ? $search : null);
        $progress = $this->store->progressMap($ctx['tenant'], $ctx['user_id'], $rows->pluck('id')->map(fn ($i) => (int) $i)->all());

        return response()->json(['status' => true, 'data' => [
            'simulations' => $rows->map(fn ($s) => $this->store->card($s, $progress[$s->id] ?? null))->values(),
            'categories' => $this->store->categories($rows),
            'total' => $rows->count(),
            'can' => $ctx['can'],
        ]]);
    }

    /** GET /lms/simulations/{id}: the card and the current specification, for the player. */
    public function show(Request $request, int $id): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $managing = $this->managing($ctx);
        $sim = $this->store->find($id, $ctx['tenant'], $managing);
        if (! $sim) {
            return $this->notFound();
        }
        $json = $this->store->specJson($sim, $this->wantedVersion($request, $managing));
        if ($json === null) {
            return $this->notFound('That version does not exist.');
        }
        $progress = $this->store->progressMap($ctx['tenant'], $ctx['user_id'], [(int) $sim->id])[$sim->id] ?? null;
        $spec = json_decode($json);

        return response()->json(['status' => true, 'data' => $this->store->card($sim, $progress, true) + [
            'spec' => $spec,
            'assets' => $this->store->assetCredits($sim, $this->store->assetHashes($spec)),
            'can' => $ctx['can'],
        ]]);
    }

    /**
     * GET /lms/simulations/{id}/assets/{sha256}: the file of a 3D model or image the simulation's CURRENT specification names, streamed from
     * the private bucket. The browser never reads the bucket; this is the only way to it, and it answers only for a hash that this simulation
     * (one the caller may see, as in show) lists in `assets.models` and that is a registered asset of the simulation's owner or of the
     * platform - so another organisation's file cannot be fetched by guessing its hash. Anything else, a missing file included, is the usual 404.
     * It is sent with the mime type the row holds; an image also gets `Content-Security-Policy: default-src 'none'; sandbox` and an inline
     * `Content-Disposition`, on top of the `nosniff` every file gets.
     */
    public function asset(Request $request, int $id, string $sha256): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $sim = $this->store->find($id, $ctx['tenant'], $this->managing($ctx));
        $json = $sim ? $this->store->specJson($sim) : null;
        $named = $json === null ? [] : $this->store->assetHashes(json_decode($json));
        $row = in_array($sha256, $named, true) ? $this->store->assetRows($sim, [$sha256])->get($sha256) : null;
        if (! $row) {
            return $this->notFound('That file does not exist.');
        }

        $etag = '"' . $row->sha256 . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff'];
        if (array_intersect($request->getETags(), ['*', $etag, 'W/' . $etag]) !== []) {
            return response('', 304, $headers);
        }

        try {
            $stream = Storage::disk(SimulationStore::ASSET_DISK)->readStream($row->storage_path);
        } catch (FilesystemException) {
            $stream = null;
        }
        if (! is_resource($stream)) {
            return $this->notFound('That file does not exist.');
        }

        // an image is shown by the page, so on top of "nosniff" it carries what stops a browser doing anything with it but showing a picture
        $image = $row->kind === 'image' ? ['Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Disposition' => 'inline; filename="asset"'] : [];

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers + $image + ['Content-Type' => $row->mime, 'Content-Length' => (string) (int) $row->bytes]);
    }

    /** GET /lms/simulations/{id}/versions (edit right) */
    public function versions(Request $request, int $id): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $sim = $this->store->owned($id, $ctx['tenant']);
        if (! $sim || ! $ctx['can']['edit']) {
            return $sim ? $this->forbidden() : $this->notFound();
        }

        return response()->json(['status' => true, 'data' => ['current' => (int) $sim->current_version, 'versions' => $this->store->versions($sim->id)]]);
    }

    /** GET /lms/simulations/catalogue (add right): platform simulations this organisation has not switched on. */
    public function catalogue(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        if (! $ctx['can']['add']) {
            return $this->forbidden();
        }

        return response()->json(['status' => true, 'data' => ['simulations' => $this->store->catalogue($ctx['tenant'])->map(fn ($s) => $this->store->card($s))->values()]]);
    }

    /**
     * GET /lms/simulations/limits (add or edit right): what the platform holds an author to, as data - the specification's caps, a model's and an
     * image's, the organisation's quota with what it uses now (`use`), the upload throttle - and the largest request this server accepts at all
     * (`server.uploadMaxBytes`, null when unlimited), so the screen can say "too big" before it uploads. See SimulationLimits.
     */
    public function limits(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        if (! $this->mayAuthor($ctx)) {
            return $this->forbidden();
        }

        return response()->json(['status' => true, 'data' => SimulationLimits::data() + [
            'use' => $this->assets->usage($ctx['tenant']),
            'server' => ['uploadMaxBytes' => SimulationLimits::serverUploadBytes()],
        ]]);
    }

    // ---------------------------------------------------------------- writing

    /**
     * POST /lms/simulations/assets (add or edit right; 30 a minute for each person). Multipart: `file` (a binary glTF model, or a PNG, JPEG or WebP
     * image), `licence` (80 characters at most) and `credit` (300) which are mandatory, `attested` (1: the person may use the file under that licence) which is mandatory, and `source` (an https address) and `name` (120) which
     * are not. The file is the caller's ORGANISATION's (never a platform asset) and what it is comes from its first bytes, not its name. A model's
     * contents are inspected before anything is stored, and the organisation's quota is respected. Answers { sha256, kind, mime, bytes, created }
     * (and the details on record: name, licence, credit, source_url): 201 when the file is new, 200 when this organisation already had it - the
     * same file twice is one asset. A problem is { message, problems[] } with 422, or 413 when the file is too big, or 503 when it could not be stored.
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        if (! $this->mayAuthor($ctx)) {
            return $this->forbidden();
        }

        try {
            $file = $request->file('file');
            if (! $file instanceof UploadedFile) {
                // PHP throws the whole body away when it is larger than post_max_size, so the file is "missing" for a different reason then
                $limit = SimulationLimits::iniBytes((string) ini_get('post_max_size'));
                if ($limit > 0 && (int) $request->server('CONTENT_LENGTH') > $limit) {
                    throw new SimulationException(['The upload is larger than this server accepts (' . round($limit / 1048576, 1) . ' MB). Send a smaller file, or ask your administrator to raise the limit.'], 413);
                }
                throw new SimulationException(['"file" is required: send the 3D model or image as the multipart field "file".']);
            }
            if (! $file->isValid()) {
                $tooBig = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                throw new SimulationException([$tooBig ? 'The file is larger than this server accepts (' . round((SimulationLimits::serverUploadBytes() ?? 0) / 1048576, 1) . ' MB). Send a smaller file, or ask your administrator to raise the limit.' : 'The upload did not arrive: ' . $file->getErrorMessage()], $tooBig ? 413 : 422);
            }

            $details = [];
            $wrong = [];
            foreach (['licence', 'credit', 'source', 'name'] as $key) {
                $value = $request->input($key);
                if ($value !== null && ! is_string($value)) {
                    $wrong[] = "\"{$key}\" must be text.";
                }
                $details[$key] = is_string($value) ? $value : null;
            }
            if ($wrong !== []) {
                throw new SimulationException($wrong);
            }
            // the person confirms they may use this file under the licence they named; the row (who, when) is the record of it
            if (! filter_var($request->input('attested'), FILTER_VALIDATE_BOOLEAN)) {
                throw new SimulationException(['Confirm that you may use this file under the licence you named: send "attested" as 1.']);
            }

            $result = $this->assets->upload($ctx['tenant'], $ctx['user_id'], (string) $file->getRealPath(), $file->getClientOriginalName(), $details);
        } catch (SimulationException $e) {
            return $this->invalid($e, 'file');
        }

        return response()->json(['status' => true, 'data' => $result], $result['created'] ? 201 : 200);
    }

    /**
     * POST /lms/simulations (add right). Body: { "spec": { ...a g2g.sim/1 or g2g.sim/2 specification... }, "status"?, "category"?, "tags"?, "cover_url"?, "note"? }.
     * The specification is read from the raw body, not from the parsed input, so `{}` stays `{}` (PHP turns an empty JSON object into an empty
     * array on the way in).
     */
    public function store(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        if (! $ctx['can']['add']) {
            return $this->forbidden();
        }

        try {
            [$specJson, $options] = $this->bodyParts($request);
            $sim = $this->store->create($ctx['tenant'], $ctx['user_id'], $specJson, $options);
        } catch (SimulationException $e) {
            return $this->invalid($e);
        }

        return response()->json(['status' => true, 'data' => $this->store->card($sim)], 201);
    }

    /**
     * PUT /lms/simulations/{id} (edit right). A new "spec" becomes a new version; the card fields (category, tags, cover_url, difficulty,
     * status, sort_order) change the card only.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $sim = $this->store->owned($id, $ctx['tenant']);
        if (! $sim) {
            return $this->notFound();
        }
        if (! $ctx['can']['edit']) {
            return $this->forbidden();
        }

        try {
            [$specJson, $options] = $this->bodyParts($request, false);
            if ($specJson !== null) {
                $sim = $this->store->addVersion($sim, $ctx['user_id'], $specJson, $options['note'] ?? null);
            }
            $sim = $this->store->updateCard($sim, $ctx['user_id'], array_intersect_key($options, array_flip(['category', 'tags', 'cover_url', 'difficulty', 'status', 'sort_order'])));
        } catch (SimulationException $e) {
            return $this->invalid($e);
        }

        return response()->json(['status' => true, 'data' => $this->store->card($sim)]);
    }

    /** DELETE /lms/simulations/{id} (delete right): removes it from every gallery; it can be restored. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $sim = $this->store->owned($id, $ctx['tenant']);
        if (! $sim) {
            return $this->notFound();
        }
        if (! $ctx['can']['delete']) {
            return $this->forbidden();
        }
        $this->store->softDelete($sim, $ctx['user_id']);

        return response()->json(['status' => true, 'data' => ['id' => (int) $sim->id, 'deleted' => true]]);
    }

    /** POST /lms/simulations/{id}/restore (delete right): the undo of a delete. */
    public function restore(Request $request, int $id): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $sim = $this->store->owned($id, $ctx['tenant'], true);
        if (! $sim) {
            return $this->notFound();
        }
        if (! $ctx['can']['delete']) {
            return $this->forbidden();
        }
        $this->store->restore($sim, $ctx['user_id']);

        return response()->json(['status' => true, 'data' => $this->store->card($this->store->owned($id, $ctx['tenant']))]);
    }

    /** POST /lms/simulations/{id}/enable (add right): adds a platform simulation to this organisation's gallery. */
    public function enable(Request $request, int $id): JsonResponse
    {
        return $this->switchOn($request, $id, true, 'add');
    }

    /** POST /lms/simulations/{id}/disable (delete right): takes a platform simulation out of this organisation's gallery. */
    public function disable(Request $request, int $id): JsonResponse
    {
        return $this->switchOn($request, $id, false, 'delete');
    }

    /**
     * POST /lms/simulations/{id}/progress (view right). Body: { percent, step, status: "in_progress"|"completed", result? }.
     * Only ever about the caller: the user is the token's.
     */
    public function progress(Request $request, int $id): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        $sim = $this->store->find($id, $ctx['tenant'], $this->managing($ctx));
        if (! $sim) {
            return $this->notFound();
        }
        $row = $this->store->recordProgress($sim, $ctx['tenant'], $ctx['user_id'], [
            'percent' => $request->input('percent'),
            'step' => $request->input('step'),
            'status' => $request->input('status'),
            'result' => $request->input('result'),
        ]);

        return response()->json(['status' => true, 'data' => ['status' => $row->status, 'percent' => (int) $row->percent, 'step' => (int) $row->step, 'completed_at' => $row->completed_at]]);
    }

    // ---------------------------------------------------------------- helpers

    private function switchOn(Request $request, int $id, bool $on, string $right): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        if (! $ctx['can'][$right]) {
            return $this->forbidden();
        }
        // only a published simulation can be switched ON; switching OFF has to work for one that has since been withdrawn, because managers
        // still see it in their gallery (they see every status)
        $sim = \Illuminate\Support\Facades\DB::table('g2g_simulation')->where('id', $id)->whereNull('deleted_at')->whereNull('sub_institute_id')
            ->when($on, fn ($q) => $q->where('status', 'published'))->first();
        if (! $sim) {
            return $this->notFound();
        }
        try {
            $this->store->setEnabled($sim, $ctx['tenant'], $on, $ctx['user_id']);
        } catch (SimulationException $e) {
            return $this->invalid($e);
        }

        return response()->json(['status' => true, 'data' => ['id' => (int) $sim->id, 'enabled' => $on]]);
    }

    /**
     * The caller, their organisation and what they may do here. Rights are the matrix on the menu row, or the administrator / HR role.
     *
     * @return array{user_id: int, tenant: int, can: array{view: bool, add: bool, edit: bool, delete: bool}}|JsonResponse
     */
    private function context(Request $request): array|JsonResponse
    {
        // resolveApiIdentity, not lmsIdentity: the latter keeps its answer on the controller object, which a long-lived worker (or a test that
        // calls the same route with two different tokens) reuses for the next request - and the next caller would be treated as the first one.
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        // For an account that belongs to no organisation, resolveApiIdentity falls back to whichever one the request names (meant for old
        // rows). Here that would let an administrator account act inside any organisation, so it gets none: the organisation is the token's.
        if ((int) ($identity['user']->sub_institute_id ?? 0) <= 0) {
            return $this->forbidden();
        }

        // The role is decided exactly as the route gate decides it (RequireAnyAccess), not by lmsRoleMatches: that one has a shorter list of
        // legacy profile names and does not know a profile literally named "Administrator", which the gate lets through.
        $role = RoleKey::satisfies(RoleKey::forUser($identity['user']), ['admin', 'hr']);
        $menuId = MenuRight::idForAccessLink(self::MENU_LINK);
        $profileId = (int) ($identity['user']->user_profile_id ?? 0);
        $can = fn (string $action): bool => $role || ($menuId !== null && $profileId > 0 && MenuRight::can($profileId, (int) $menuId, $identity['sub_institute_id'], $action));

        return [
            'user_id' => $identity['user_id'],
            'tenant' => $identity['sub_institute_id'],
            'can' => ['view' => $can('view'), 'add' => $can('add'), 'edit' => $can('edit'), 'delete' => $can('delete')],
        ];
    }

    /**
     * Whether the caller manages simulations: anyone who may add, change or delete them. They see (and so must be able to play) the drafts and
     * archived ones of their own organisation, which learners do not; index, show and progress all ask this one question.
     *
     * @param  array{can: array{add: bool, edit: bool, delete: bool}}  $ctx
     */
    private function managing(array $ctx): bool
    {
        return $ctx['can']['edit'] || $ctx['can']['add'] || $ctx['can']['delete'];
    }

    /**
     * Whether the caller may write simulations: the same people who may create one (add) or save a new version of one (edit). They are who
     * need to upload a file or read the limits; the route gate (`anyaccess`) lets exactly these through.
     *
     * @param  array{can: array{add: bool, edit: bool}}  $ctx
     */
    private function mayAuthor(array $ctx): bool
    {
        return $ctx['can']['add'] || $ctx['can']['edit'];
    }

    /** A version other than the current one may be asked for only by people who manage simulations. */
    private function wantedVersion(Request $request, bool $managing): ?int
    {
        $v = $request->query('version');

        return $managing && is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /**
     * The "spec" of the raw body as JSON text, and the other fields as options.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function bodyParts(Request $request, bool $specRequired = true): array
    {
        $raw = $request->getContent();
        // the specification is at most 1 MB and the other fields are small: a body far over that is refused before anything decodes it
        if (strlen($raw) > SimulationStore::MAX_SPEC_BYTES + 100000) {
            throw new SimulationException(['The request is larger than a simulation may be: the specification is at most 1 MB.'], 413);
        }
        try {
            $body = $raw === '' ? new \stdClass() : json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SimulationException(['The request body is not valid JSON.']);
        }
        if ($body === []) {
            $body = new \stdClass();   // an empty JSON object arrives as an empty array
        }
        if (! $body instanceof \stdClass) {
            throw new SimulationException(['The request body must be a JSON object.']);
        }

        $spec = null;
        if (property_exists($body, 'spec') && $body->spec !== null) {
            $spec = is_string($body->spec) ? $body->spec : json_encode($body->spec, SimulationStore::JSON_FLAGS);
        }
        if ($spec === null && $specRequired) {
            throw new SimulationException(['"spec" is required: the simulation specification.']);
        }

        // Each option is text (or null), the order is a number and the tags are words: anything else is said so here, as a list of problems,
        // instead of being left to fail inside the store as a server error.
        $options = [];
        $problems = [];
        foreach (['status', 'category', 'cover_url', 'difficulty', 'note'] as $key) {
            if (property_exists($body, $key)) {
                $options[$key] = $body->{$key};
                if ($options[$key] !== null && ! is_string($options[$key])) {
                    $problems[] = "\"{$key}\" must be text.";
                }
            }
        }
        if (property_exists($body, 'sort_order')) {
            $options['sort_order'] = $body->sort_order;
            if ($options['sort_order'] !== null && ! is_numeric($options['sort_order'])) {
                $problems[] = '"sort_order" must be a number.';
            }
        }
        if (property_exists($body, 'tags')) {
            $tags = $options['tags'] = $body->tags;
            $isWord = fn ($t) => is_string($t) || is_int($t) || is_float($t);
            if ($tags !== null && ! is_string($tags) && ! (is_array($tags) && count(array_filter($tags, $isWord)) === count($tags))) {
                $problems[] = '"tags" must be a list of words.';
            }
        }
        if ($problems !== []) {
            throw new SimulationException($problems);
        }

        return [$spec, $options];
    }

    private function notFound(string $message = 'Simulation not found.'): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $message], 404);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'You do not have permission to do that.'], 403);
    }

    /** `$field` is what `errors` is keyed by: the specification for a specification, the file for an upload. */
    private function invalid(SimulationException $e, string $field = 'spec'): JsonResponse
    {
        // `errors` is the shape the front end's ApiError already reads; `problems` is the same list under its own name
        return response()->json(['status' => false, 'message' => $e->getMessage(), 'errors' => [$field => $e->problems], 'problems' => $e->problems], $e->getCode() >= 400 ? $e->getCode() : 422);
    }
}
