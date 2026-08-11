<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * X-08(b), part 1 — THE DRY RUN. Bring your own competency framework.
 *
 * A hospital group with 400 nurses already HAS a framework - in a spreadsheet,
 * from a consultancy, or in the system they are replacing. **Making them retype
 * it is the current behaviour and the worst possible first impression.**
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS IS NOT `skillLibraryController::competencyLibraryImport`.
 *
 *   THAT importer writes `s_users_skills` - FLAT SKILL ROWS. It is named for
 *   competency and operates on skills, which is a module-level naming failure
 *   recorded in the register, not a bug to fix here.
 *
 *   THIS one writes `competency` + `competency_kasba_item` - KASBA BUNDLES,
 *   across ALL FIVE DIMENSIONS. A customer's framework carries knowledge,
 *   ability, attitude and behaviour items, and an importer accepting only skills
 *   would resolve as badly as the generic library does (G-SEED-01, corrected).
 *
 *   BOTH STAY. The skill importer works for what it does and 5,171 rows arrived
 *   through it; replacing it would be a deletion in effect.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * IT WRITES NOTHING. That is the whole point of this half: report what WOULD be
 * created, what WOULD resolve and what WOULD be held, before a customer commits
 * to any of it. Same shape as X-08(a), same principle as X-13 and the 9-box -
 * the system does not manufacture a claim nobody made.
 *
 * AND IT DOES NOT GRADE THE VOCABULARY. There is no validator rejecting words it
 * does not recognise: **the customer's words ARE the content.** An unmatched item
 * is HELD as a label with its text retained (F-07b), never dropped and never
 * "corrected" to something the library already knows.
 */
class FrameworkImportController extends Controller
{
    use ResolvesApiIdentity;

    /** Q-A2's five. An item is one of these or the row is rejected for SHAPE, not vocabulary. */
    private const DIMENSIONS = ['knowledge', 'attitude', 'skill', 'behaviour', 'ability'];

    public function dryRun(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'rows'                 => 'required|array|min:1|max:5000',
            'rows.*.competency'    => 'required|string|max:191',
            'rows.*.item'          => 'required|string|max:191',
            'rows.*.dimension'     => 'required|string|max:32',
            'rows.*.weight'        => 'nullable|numeric|min:0|max:100',
            'rows.*.description'   => 'nullable|string',
        ]);

        $tenant = $identity['sub_institute_id'];

        // The tenant's canonical skill vocabulary, lower-cased once. An item
        // resolves ONLY against the caller's own rows - a name means whatever the
        // caller's library says it means, which is the one place that question
        // has a single answer (Q-C1).
        $canonical = DB::table('s_users_skills')
            ->where('sub_institute_id', $tenant)
            ->whereNotNull('title')->where('title', '!=', '')
            ->pluck('id', 'title')->all();
        $lookup = [];
        foreach ($canonical as $title => $id) {
            $lookup[mb_strtolower(trim((string) $title))] = (int) $id;
        }

        $existing = DB::table('competency')
            ->where('sub_institute_id', $tenant)
            ->pluck('id', 'name')->all();
        $existingLower = [];
        foreach ($existing as $name => $id) {
            $existingLower[mb_strtolower(trim((string) $name))] = (int) $id;
        }

        $competencies = [];
        $badShape = [];
        $resolved = 0;
        $held = 0;
        $byDimension = array_fill_keys(self::DIMENSIONS, ['items' => 0, 'resolved' => 0]);

        foreach ($data['rows'] as $i => $row) {
            $dimension = mb_strtolower(trim($row['dimension']));

            // SHAPE, NOT VOCABULARY. A dimension outside Q-A2's five is a
            // structural problem with the file; an unrecognised ITEM NAME is not
            // a problem at all.
            if (!in_array($dimension, self::DIMENSIONS, true)) {
                $badShape[] = ['row' => $i + 1, 'reason' => "'{$row['dimension']}' is not one of: " . implode(', ', self::DIMENSIONS)];
                continue;
            }

            $compName = trim($row['competency']);
            $itemName = trim($row['item']);
            $key = mb_strtolower($compName);

            $competencies[$key] ??= [
                'name'        => $compName,
                'already_exists' => isset($existingLower[$key]),
                'items'       => [],
            ];

            $itemId = $lookup[mb_strtolower($itemName)] ?? null;
            $itemId === null ? $held++ : $resolved++;
            $byDimension[$dimension]['items']++;
            if ($itemId !== null) {
                $byDimension[$dimension]['resolved']++;
            }

            $competencies[$key]['items'][] = [
                'item'      => $itemName,
                'dimension' => $dimension,
                'weight'    => $row['weight'] ?? 1.0,
                // TARGET if the tenant already has this skill; HOLDING otherwise,
                // with the text kept exactly as the customer wrote it.
                'resolves_to' => $itemId,
                'state'     => $itemId === null ? 'HELD_AS_LABEL' : 'TARGET',
            ];
        }

        $totalItems = $resolved + $held;
        $newCompetencies = count(array_filter($competencies, fn ($c) => !$c['already_exists']));

        return response()->json(['status' => 1, 'data' => [
            'would_create' => [
                'competencies'       => $newCompetencies,
                'competencies_seen'  => count($competencies),
                'already_existing'   => count($competencies) - $newCompetencies,
                'kasba_items'        => $totalItems,
            ],
            'vocabulary' => [
                'resolved'         => $resolved,
                'held_as_label'    => $held,
                'percent_resolved' => $totalItems > 0 ? round(100 * $resolved / $totalItems, 1) : null,
                'by_dimension'     => $byDimension,
            ],
            // A file whose ROWS are malformed. Never a list of words we did not like.
            'rejected_rows' => $badShape,
            'competencies'  => array_values($competencies),
            'note' => 'NOTHING HAS BEEN WRITTEN. Items that do not match your skill library are '
                    . 'HELD as labels with your wording kept - they are not dropped and not '
                    . 'renamed. Most items in a real framework arrive this way, which is the '
                    . 'normal first state.',
        ]]);
    }
}
