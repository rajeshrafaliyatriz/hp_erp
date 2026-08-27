<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use App\Services\Competency\EsoExporter;
use App\Services\Competency\EsoGenerator;
use App\Services\Competency\TaskExecutionClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * ESO — the execution model for a task. §5 of the ESO v1 document.
 *
 * `TaskExecutionController` answers HOW MUCH of a role could be automated.
 * This answers HOW a given task is actually executed: the steps, who does each
 * one, what may not be done, and what evidence comes out.
 *
 * ── TEMPLATE vs INSTANCE ────────────────────────────────────────────────────
 *
 * A Template is a generic execution pattern with no tenant - product IP, and
 * visible to every tenant. An Instance is one customer's version, seeded from a
 * template and scoped to them.
 *
 * That asymmetry is the security-relevant part of this file: **a tenant may READ
 * every template and WRITE none of them.** Without that rule one customer could
 * edit a pattern every other customer inherits.
 */
class EsoController extends Controller
{
    use ResolvesCompetencyContext;

    /** §5.4. Draft is where anything new lands, including anything generated. */
    public const STATUSES = ['Draft', 'Reviewed', 'Published', 'Retired'];

    /** The six §5 list fields, stored as JSON in LONGTEXT (live is MariaDB 10.1). */
    public const LIST_FIELDS = [
        'human_decision_points', 'escalation_triggers', 'steps',
        'inputs', 'outputs', 'required_controls', 'prohibited_actions',
        'evidence_emitted',
    ];

    /** Plain text/scalar fields a caller may write. */
    private const TEXT_FIELDS = [
        'title', 'objective', 'expected_outcome',
        'human_responsibility', 'agent_responsibility', 'execution_mode',
    ];

    public function __construct(private readonly EsoGenerator $generator)
    {
    }

    /**
     * GET /competency/eso
     *
     * Templates (shared) plus this tenant's own instances. Never another
     * tenant's instances.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $rows = DB::table('eso')
            ->whereNull('deleted_at')
            // A Template has no tenant and belongs to everyone; an Instance
            // belongs to exactly one. This where-group is the whole boundary.
            ->where(function ($q) use ($sid) {
                $q->where('scope', 'Template')->orWhere('sub_institute_id', $sid);
            })
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->input('scope')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('execution_mode'), fn ($q) => $q->where('execution_mode', $request->input('execution_mode')))
            ->when($request->filled('task_id'), fn ($q) => $q->where('user_jobrole_task_id', (int) $request->input('task_id')))
            ->orderBy('scope')->orderBy('title')
            ->limit(500)
            ->get();

        return response()->json([
            'status'   => 1,
            'data'     => $rows->map(fn ($r) => $this->present($r))->values(),
            'statuses' => self::STATUSES,
            'modes'    => TaskExecutionClassifier::MODES,
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => 'No execution models have been written yet. Author one, or generate a draft from a task.',
        ]);
    }

    /** GET /competency/eso/{id} */
    public function show(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = $this->findVisible($id, (int) $context['sub_institute_id']);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That execution model does not exist, or belongs to another organisation.'], 404);
        }

        return response()->json([
            'status' => 1,
            'data'   => $this->present($row),
            'statuses' => self::STATUSES,
            'modes'  => TaskExecutionClassifier::MODES,
        ]);
    }

    /** POST /competency/eso */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'title'   => 'required|string|max:191',
            'scope'   => 'required|string|in:Template,Instance',
            'execution_mode' => 'nullable|string',
            'user_jobrole_task_id' => 'nullable|integer',
            'eso_template_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if ($request->filled('execution_mode')
            && !array_key_exists($request->input('execution_mode'), TaskExecutionClassifier::MODES)) {
            return response()->json(['status' => 0, 'message' => 'That is not a known execution mode.'], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $scope = (string) $request->input('scope');

        $id = DB::table('eso')->insertGetId($this->writable($request) + [
            'scope' => $scope,
            // A Template is tenant-less by definition. Stamping a tenant on one
            // would quietly turn shared IP into one customer's private copy.
            'sub_institute_id' => $scope === 'Template' ? null : $sid,
            'user_jobrole_task_id' => $request->input('user_jobrole_task_id'),
            'eso_template_id' => $request->input('eso_template_id'),
            'status'  => 'Draft',
            'version' => 1,
            'source'  => 'human',
            'created_by' => $context['user_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit($context, 'created', $id, (string) $request->input('title'), []);

        return response()->json([
            'status' => 1, 'data' => ['id' => $id],
            'message' => "Execution model created as a Draft. Publish it when it is ready to be used.",
        ], 201);
    }

    /** PUT /competency/eso/{id} */
    public function update(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $row = $this->findVisible($id, $sid);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That execution model does not exist, or belongs to another organisation.'], 404);
        }

        if (($refusal = $this->refuseIfNotWritable($row, $sid)) !== null) {
            return $refusal;
        }

        DB::table('eso')->where('id', $id)->update($this->writable($request) + [
            'updated_by' => $context['user_id'],
            'updated_at' => now(),
        ]);

        $this->audit($context, 'updated', $id, (string) ($request->input('title') ?? $row->title), []);

        return response()->json(['status' => 1, 'message' => 'Execution model saved.']);
    }

    /**
     * POST /competency/eso/{id}/status
     *
     * §5.4's lifecycle. Publishing is the moment an ESO becomes something people
     * are expected to follow, so it is a deliberate act and it is audited.
     */
    public function setStatus(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:' . implode(',', self::STATUSES),
            'note'   => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $row = $this->findVisible($id, $sid);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That execution model does not exist, or belongs to another organisation.'], 404);
        }

        if (($refusal = $this->refuseIfNotWritable($row, $sid)) !== null) {
            return $refusal;
        }

        $new = (string) $request->input('status');

        /*
         * A GENERATED DRAFT CANNOT BE PUBLISHED WITHOUT BEING READ.
         *
         * Same rule the classification layer already holds: a model's output is
         * a proposal until a person confirms it. Going straight from an
         * ai-generated Draft to Published would put a machine's description of
         * how to do somebody's job into force with nobody having read it.
         */
        if ($new === 'Published' && $row->source === 'ai-generated' && $row->status === 'Draft') {
            return response()->json([
                'status'  => 0,
                'reason'  => 'unreviewed_generation',
                'message' => 'This execution model was written by AI and nobody has reviewed it yet. '
                    . 'Move it to Reviewed first — that is the step where a person confirms the steps '
                    . 'and controls are actually right.',
            ], 422);
        }

        DB::table('eso')->where('id', $id)->update([
            'status' => $new,
            // §5.3. Publishing cuts a new version so a published procedure that
            // changes is not silently a different document under the same number.
            'version' => $new === 'Published' ? ((int) $row->version + 1) : $row->version,
            'updated_by' => $context['user_id'], 'updated_at' => now(),
        ]);

        $this->audit($context, strtolower($new), $id, (string) $row->title, [[
            'field' => 'status', 'label' => 'Status', 'old' => $row->status, 'new' => $new,
        ]]);

        return response()->json([
            'status' => 1,
            'message' => "Execution model moved to $new.",
        ]);
    }

    /** DELETE /competency/eso/{id} — soft. */
    public function destroy(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $row = $this->findVisible($id, $sid);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That execution model does not exist, or belongs to another organisation.'], 404);
        }

        if (($refusal = $this->refuseIfNotWritable($row, $sid)) !== null) {
            return $refusal;
        }

        DB::table('eso')->where('id', $id)->update(['deleted_at' => now(), 'updated_by' => $context['user_id']]);
        $this->audit($context, 'deleted', $id, (string) $row->title, []);

        return response()->json(['status' => 1, 'message' => 'Execution model removed.']);
    }

    /**
     * POST /competency/eso/generate — the §6.3 "Generate ESO with AI" action.
     *
     * Writes a Draft, marked `ai-generated`, attached to one task. It is a
     * starting point for a person, not a procedure anybody should follow yet.
     */
    public function generate(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'user_jobrole_task_id' => 'required|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $result = $this->generator->generateForTask(
            (int) $context['sub_institute_id'],
            (int) $request->input('user_jobrole_task_id'),
            $context['user_id'] !== null ? (int) $context['user_id'] : null,
        );

        $refusals = [
            'not_configured' => ['AI is not configured on this server, so nothing was generated.', 503],
            'insufficient_balance' => ['The AI account balance is too low to run this safely, so nothing was sent and nothing was charged.', 402],
            'truncated' => ['The model ran out of room before finishing, so the draft could not be used.', 502],
            'ai_error'  => ['The model could not be reached, so nothing was generated. Nothing was guessed at.', 502],
            'no_task'   => ['That task does not exist in your organisation.', 404],
            'exists'    => ['This task already has an execution model. Open it rather than generating a second one.', 409],
        ];

        if ($result['reason'] !== null) {
            [$message, $code] = $refusals[$result['reason']];
            return response()->json([
                'status' => 0, 'reason' => $result['reason'],
                'detail' => $result['detail'] ?? null,
                'message' => $message, 'data' => $result,
            ], $code);
        }

        $this->audit($context, 'generated', (int) $result['id'], (string) $result['title'], []);

        return response()->json([
            'status' => 1, 'data' => $result,
            'message' => 'A Draft execution model was generated. It has not been reviewed by anyone — '
                . 'read the steps and controls before moving it to Reviewed.',
        ], 201);
    }

    /**
     * GET /competency/eso/{id}/export?format=md|pdf
     *
     * An ESO that cannot leave the screen is useless to both of its readers.
     *
     *   md   for an agent — YAML front matter it can parse, then the body
     *   pdf  for a person — a printable SOP for a binder or an auditor
     *
     * Both carry the status and source INSIDE the file. A document that leaves
     * the system loses the badge and the warning banner around it, so an
     * unreviewed AI draft has to announce itself on its own face or it will be
     * read as an agreed procedure.
     */
    public function export(Request $request, int $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = $this->findVisible($id, (int) $context['sub_institute_id']);
        if (!$row) {
            return response()->json(['status' => 0, 'message' => 'That execution model does not exist, or belongs to another organisation.'], 404);
        }

        $format = strtolower((string) $request->input('format', 'md'));
        if (!in_array($format, ['md', 'pdf'], true)) {
            return response()->json([
                'status' => 0,
                'message' => 'Choose a format: md for an agent, pdf for a person.',
            ], 422);
        }

        $exporter = app(EsoExporter::class);
        $task = $exporter->taskFor($row);

        $this->audit($context, 'exported', $id, (string) $row->title, []);

        if ($format === 'md') {
            return response($exporter->toMarkdown($row, $task), 200, [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $exporter->filename($row, 'md') . '"',
            ]);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'competency.eso-pdf',
            $exporter->viewData($row, $task)
        )->setPaper('a4', 'portrait');

        /*
         * Raw bytes rather than ->download(): stray output anywhere in the app
         * (a newline after a closing PHP tag, say) otherwise lands ahead of the
         * %PDF header and strict readers reject the file. Same guard as the
         * certificate export in LmsLearningController.
         */
        $output = $pdf->output();
        $headerAt = strpos($output, '%PDF');
        if ($headerAt !== false && $headerAt > 0) {
            $output = substr($output, $headerAt);
        }

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $exporter->filename($row, 'pdf') . '"',
            'Content-Length' => (string) strlen($output),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Shared
     * ------------------------------------------------------------------ */

    private function findVisible(int $id, int $sid)
    {
        return DB::table('eso')->where('id', $id)->whereNull('deleted_at')
            ->where(function ($q) use ($sid) {
                $q->where('scope', 'Template')->orWhere('sub_institute_id', $sid);
            })
            ->first();
    }

    /**
     * A tenant may read every Template and write none of them.
     *
     * Templates are shared across every organisation. Letting one tenant edit
     * one would let them change a procedure everybody else inherits, which is a
     * cross-tenant write wearing a read's clothing.
     */
    private function refuseIfNotWritable(object $row, int $sid)
    {
        if ($row->scope === 'Template') {
            return response()->json([
                'status'  => 0,
                'reason'  => 'template_readonly',
                'message' => 'This is a shared execution template used by every organisation, so it '
                    . 'cannot be edited here. Create an Instance from it and change that instead.',
            ], 403);
        }

        if ((int) $row->sub_institute_id !== $sid) {
            return response()->json(['status' => 0, 'message' => 'That execution model belongs to another organisation.'], 403);
        }

        return null;
    }

    /** Only the fields a caller is allowed to set, with lists encoded once. */
    private function writable(Request $request): array
    {
        $out = [];

        foreach (self::TEXT_FIELDS as $field) {
            if ($request->exists($field)) {
                $out[$field] = $request->input($field);
            }
        }

        foreach (self::LIST_FIELDS as $field) {
            if (!$request->exists($field)) {
                continue;
            }
            $value = $request->input($field);
            // Stored as JSON text because live is MariaDB 10.1 with no JSON type.
            $out[$field] = ($value === null || $value === []) ? null : json_encode(array_values((array) $value));
        }

        return $out;
    }

    /** Decode the list fields so a client never has to parse strings. */
    private function present(object $row): array
    {
        $out = (array) $row;

        foreach (self::LIST_FIELDS as $field) {
            $raw = $out[$field] ?? null;
            $decoded = $raw !== null && $raw !== '' ? json_decode((string) $raw, true) : null;
            // A field that will not decode is returned as an empty list rather
            // than as a broken string a screen would try to render.
            $out[$field] = is_array($decoded) ? $decoded : [];
        }

        // How complete this ESO actually is. §6.4's whole purpose is finding out
        // which of the 18 fields people fill in, so the answer travels with the row.
        $filled = 0;
        foreach (array_merge(self::TEXT_FIELDS, self::LIST_FIELDS) as $field) {
            $v = $out[$field] ?? null;
            if ($v !== null && $v !== '' && $v !== []) {
                $filled++;
            }
        }
        $out['fields_filled'] = $filled;
        $out['fields_total']  = count(self::TEXT_FIELDS) + count(self::LIST_FIELDS);

        return $out;
    }

    private function audit(array $context, string $action, int $id, string $title, array $changes): void
    {
        try {
            $this->logCompetencyActivity(
                (int) $context['sub_institute_id'],
                $context['user_id'] !== null ? (int) $context['user_id'] : null,
                $action,
                sprintf('%s execution model "%s".', ucfirst($action), $title),
                'eso',
                $id,
                $title,
                $changes ?: null
            );
        } catch (\Throwable $e) {
            Log::warning('ESO change applied but not written to the activity log', [
                'action' => $action, 'id' => $id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
