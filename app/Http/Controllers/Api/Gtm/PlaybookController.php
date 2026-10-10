<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmPlaybook;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * GTM playbooks: role playbooks, qualification methodologies, templates and workflow
 * definitions that the GTM agents follow.
 *
 * Two layers. Platform defaults (sub_institute_id NULL) are read-only through this API;
 * an organisation changes one by copying it ("customise"), which creates its own row with
 * the same slug. The copy overrides the default for that organisation only, so one
 * tenant's edit can never reach another. Every edit bumps `version` and writes a row to
 * gtm_playbook_versions, so a change is never destructive and can be restored.
 */
class PlaybookController extends Controller
{
    use ResolvesApiIdentity;

    public const KINDS = ['role_playbook', 'methodology', 'industry_pack', 'template', 'workflow'];
    public const ROLES = ['sdr', 'ae', 'marketing', 'revops', 'csm', 'all'];
    public const STATUSES = ['active', 'draft', 'archived'];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $rows = GtmPlaybook::query()
            ->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $t))
            ->when(in_array($request->input('kind'), self::KINDS, true), fn ($q) => $q->where('kind', $request->input('kind')))
            ->when(in_array($request->input('role'), self::ROLES, true), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('stage'), fn ($q) => $q->where('stage', (string) $request->input('stage')))
            ->orderBy('kind')->orderBy('title')->get();

        $defaultSlugs = GtmPlaybook::whereNull('sub_institute_id')->pluck('slug')->all();
        // A tenant row hides the platform default with the same slug - that is the override.
        $own = $rows->where('sub_institute_id', $t)->keyBy('slug');
        $status = (string) $request->input('status', '');
        $items = $rows->filter(fn ($p) => $p->sub_institute_id !== null || ! $own->has($p->slug))
            ->filter(fn ($p) => $status !== '' ? $p->status === $status : $p->status !== 'archived')
            ->values()
            ->map(fn ($p) => $this->present($p, $p->sub_institute_id !== null && in_array($p->slug, $defaultSlugs, true)));

        return response()->json(['status' => 1, 'data' => ['items' => $items]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $playbook = $this->visible($identity['sub_institute_id'], $id);
        if (! $playbook) {
            return $this->notFound();
        }
        $versions = DB::table('gtm_playbook_versions')->where('playbook_id', $playbook->id)->orderByDesc('version')
            ->get(['version', 'title', 'change_note', 'changed_by', 'created_at']);

        return response()->json(['status' => 1, 'data' => ['playbook' => $this->present($playbook), 'versions' => $versions]]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $data = $v->validated();
        if ($err = $this->definitionError($data)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }
        if (GtmPlaybook::where('sub_institute_id', $t)->where('slug', $data['slug'])->exists()) {
            return response()->json(['status' => 0, 'message' => 'You already have a playbook with this slug.'], 422);
        }

        $playbook = DB::transaction(function () use ($data, $t, $identity) {
            $p = GtmPlaybook::create($data + [
                'sub_institute_id' => $t, 'version' => 1, 'is_system' => false, 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
            ]);
            $this->snapshot($p, 'Created', $identity['user_id']);

            return $p;
        });
        GtmAudit::record('gtm.playbook.created', $t, 'gtm_playbooks', $playbook->id, $identity['user_id'], ['slug' => $playbook->slug, 'kind' => $playbook->kind]);

        return response()->json(['status' => 1, 'data' => ['playbook' => $this->present($playbook)]], 201);
    }

    /** Copy a platform default into this organisation so it can be edited. Idempotent. */
    public function customise(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $source = $this->visible($t, $id);
        if (! $source) {
            return $this->notFound();
        }
        $existing = GtmPlaybook::where('sub_institute_id', $t)->where('slug', $source->slug)->first();
        if ($existing) {
            return response()->json(['status' => 1, 'data' => ['playbook' => $this->present($existing), 'created' => false]]);
        }
        if ($source->sub_institute_id !== null) {
            return response()->json(['status' => 0, 'message' => 'This playbook already belongs to your organisation.'], 422);
        }

        $copy = DB::transaction(function () use ($source, $t, $identity) {
            $p = GtmPlaybook::create([
                'sub_institute_id' => $t, 'kind' => $source->kind, 'role' => $source->role, 'stage' => $source->stage, 'slug' => $source->slug,
                'title' => $source->title, 'description' => $source->description, 'body' => $source->body, 'inputs' => $source->inputs,
                'output_schema' => $source->output_schema, 'definition' => $source->definition, 'status' => 'active', 'version' => 1,
                'is_system' => false, 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
            ]);
            $this->snapshot($p, "Customised from platform default v{$source->version}", $identity['user_id']);

            return $p;
        });
        GtmAudit::record('gtm.playbook.customised', $t, 'gtm_playbooks', $copy->id, $identity['user_id'], ['slug' => $copy->slug, 'from_default_id' => $source->id]);

        return response()->json(['status' => 1, 'data' => ['playbook' => $this->present($copy), 'created' => true]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $playbook = $this->visible($t, $id);
        if (! $playbook) {
            return $this->notFound();
        }
        if ($playbook->sub_institute_id === null) {
            return response()->json(['status' => 0, 'code' => 'customise_first', 'message' => 'Platform defaults cannot be edited. Customise it first, then edit your copy.'], 422);
        }

        $v = Validator::make($request->all(), $this->rules(false) + ['change_note' => 'nullable|string|max:255']);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $data = $v->validated();
        unset($data['slug'], $data['kind']); // identity of a playbook does not change; its content does
        $note = $data['change_note'] ?? null;
        unset($data['change_note']);

        $merged = array_merge($playbook->only(['kind', 'definition']), $data);
        if ($err = $this->definitionError($merged)) {
            return response()->json(['status' => 0, 'message' => $err], 422);
        }

        $playbook = DB::transaction(function () use ($playbook, $data, $note, $identity) {
            $playbook->fill($data);
            $contentChanged = $playbook->isDirty(['title', 'description', 'body', 'inputs', 'output_schema', 'definition']);
            if ($contentChanged) {
                $playbook->version = (int) $playbook->version + 1;
            }
            $playbook->updated_by = $identity['user_id'];
            $playbook->save();
            if ($contentChanged) {
                $this->snapshot($playbook, $note ?: 'Edited', $identity['user_id']);
            }

            return $playbook;
        });
        GtmAudit::record('gtm.playbook.updated', $t, 'gtm_playbooks', $playbook->id, $identity['user_id'], ['version' => $playbook->version, 'changed' => array_keys($data)]);

        return response()->json(['status' => 1, 'data' => ['playbook' => $this->present($playbook)]]);
    }

    /** Roll content back to an earlier version by writing it as a NEW version (history is kept). */
    public function restore(Request $request, int $id, int $version): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $playbook = GtmPlaybook::where('sub_institute_id', $t)->find($id);
        if (! $playbook) {
            return $this->notFound();
        }
        $old = DB::table('gtm_playbook_versions')->where('playbook_id', $playbook->id)->where('version', $version)->first();
        if (! $old) {
            return response()->json(['status' => 0, 'message' => 'Version not found'], 404);
        }

        $playbook = DB::transaction(function () use ($playbook, $old, $version, $identity) {
            $playbook->forceFill([
                'title' => $old->title, 'description' => $old->description, 'body' => $old->body,
                'inputs' => json_decode($old->inputs ?? 'null', true), 'output_schema' => json_decode($old->output_schema ?? 'null', true),
                'definition' => json_decode($old->definition ?? 'null', true),
                'version' => (int) $playbook->version + 1, 'updated_by' => $identity['user_id'],
            ])->save();
            $this->snapshot($playbook, "Restored from v{$version}", $identity['user_id']);

            return $playbook;
        });
        GtmAudit::record('gtm.playbook.restored', $t, 'gtm_playbooks', $playbook->id, $identity['user_id'], ['from_version' => $version, 'version' => $playbook->version]);

        return response()->json(['status' => 1, 'data' => ['playbook' => $this->present($playbook)]]);
    }

    /** Archive, not delete: runs that cite this playbook keep their reference. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $playbook = GtmPlaybook::where('sub_institute_id', $t)->find($id);
        if (! $playbook) {
            return $this->notFound();
        }
        $playbook->forceFill(['status' => 'archived', 'updated_by' => $identity['user_id']])->save();
        GtmAudit::record('gtm.playbook.archived', $t, 'gtm_playbooks', $playbook->id, $identity['user_id'], ['slug' => $playbook->slug]);

        return response()->json(['status' => 1, 'message' => 'Playbook archived']);
    }

    /** A platform default, or this organisation's own row - never another organisation's. */
    private function visible(int $tenant, int $id): ?GtmPlaybook
    {
        return GtmPlaybook::where('id', $id)->where(fn ($w) => $w->whereNull('sub_institute_id')->orWhere('sub_institute_id', $tenant))->first();
    }

    private function snapshot(GtmPlaybook $p, string $note, ?int $userId): void
    {
        DB::table('gtm_playbook_versions')->insert([
            'playbook_id' => $p->id, 'sub_institute_id' => $p->sub_institute_id, 'version' => $p->version, 'title' => $p->title,
            'description' => $p->description, 'body' => $p->body, 'inputs' => json_encode($p->inputs), 'output_schema' => json_encode($p->output_schema),
            'definition' => json_encode($p->definition), 'change_note' => $note, 'changed_by' => $userId, 'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(GtmPlaybook $p, ?bool $overridesDefault = null): array
    {
        $overridesDefault ??= $p->sub_institute_id !== null
            && GtmPlaybook::whereNull('sub_institute_id')->where('slug', $p->slug)->exists();

        return $p->toArray() + [
            'source' => $p->sub_institute_id === null ? 'platform' : 'organisation',
            'editable' => $p->sub_institute_id !== null,
            'overrides_default' => $p->sub_institute_id !== null && $overridesDefault,
        ];
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind' => "$req|in:".implode(',', self::KINDS),
            'role' => 'sometimes|in:'.implode(',', self::ROLES),
            'stage' => 'nullable|string|max:30',
            'slug' => [$req, 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'title' => "$req|string|max:191",
            'description' => 'nullable|string|max:2000',
            'body' => "$req|string|min:20|max:20000",
            'inputs' => 'nullable|array|max:30',
            'inputs.*' => 'string|max:60',
            'output_schema' => 'nullable|array',
            'definition' => 'nullable|array',
            'status' => 'sometimes|in:'.implode(',', self::STATUSES),
        ];
    }

    /** A methodology is a scoring rubric: without well-formed dimensions it cannot score anything. */
    private function definitionError(array $data): ?string
    {
        if (($data['kind'] ?? null) !== 'methodology') {
            return null;
        }
        $dims = $data['definition']['dimensions'] ?? null;
        if (! is_array($dims) || count($dims) < 2) {
            return 'A methodology needs at least two scoring dimensions in definition.dimensions.';
        }
        $keys = [];
        foreach ($dims as $d) {
            $key = is_array($d) ? ($d['key'] ?? null) : null;
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{2,40}$/', $key) || ! is_string($d['label'] ?? null) || ! is_numeric($d['weight'] ?? null) || $d['weight'] <= 0) {
                return 'Each dimension needs a lowercase key, a label and a positive numeric weight.';
            }
            if (in_array($key, $keys, true)) {
                return "Dimension key '{$key}' is used twice.";
            }
            $keys[] = $key;
        }

        return null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Playbook not found'], 404);
    }

    private function invalid($validator): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
    }
}
