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
        return $this->run($request, false);
    }

    /**
     * WRITE. One transaction.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A HALF-IMPORTED FRAMEWORK IS NOT A SMALLER FRAMEWORK.
     *
     *   It is a competency model with holes the customer cannot see, and every
     *   gap view computed against it would be quietly wrong - the same family as
     *   every silent-wrongness this project has closed. WORSE THAN AN OUTRIGHT
     *   FAILURE.
     *
     * NOT RESUMABLE, AND THAT IS THE DECISION RATHER THAN THE DEFAULT.
     *   THE DRY RUN ALREADY BUYS WHAT RESUMABILITY WOULD. A customer sees the
     *   whole outcome before committing, so a mid-write failure is a SYSTEM
     *   FAULT, not a content surprise - and "nothing happened, here is why" is
     *   the correct response to a system fault.
     *
     *   Resumability would need a durable record of what landed, written INSIDE
     *   the operation that might fail: a second thing to get wrong, for a case a
     *   transaction removes.
     *
     * WHAT WOULD MAKE THIS WRONG, named so resumability arrives as a decision:
     *   a framework exceeding what one transaction can hold, or an import that
     *   must span a session. Neither is true at the validated 5,000-row ceiling.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function commitImport(Request $request)
    {
        return $this->run($request, true);
    }

    /**
     * THE ONE PATH. Both actions call this; `$write` is the only difference.
     *
     * TWO PATHS THAT CAN DISAGREE IS THE DEFECT THIS FEATURE EXISTS TO AVOID -
     * a preview that does not predict the write is worse than no preview, because
     * the customer acts on it.
     */
    private function run(Request $request, bool $write)
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

            // ALREADY PRESENT ON AN EXISTING COMPETENCY?
            //
            // The first version checked this ONLY in the write, so the dry run
            // predicted 4 items and the write created 3 - the preview and the
            // write disagreed, which is the one defect this feature exists to
            // avoid. The check belongs HERE, in the shared path, so the count a
            // customer sees is produced by the same code that does the work.
            $alreadyPresent = false;
            $existingCid = $existingLower[$key] ?? null;
            if ($existingCid !== null) {
                $alreadyPresent = DB::table('competency_kasba_item')
                    ->where('competency_id', $existingCid)
                    ->where('kasba_type', $dimension)
                    ->where(function ($q) use ($itemId, $itemName) {
                        $itemId !== null
                            ? $q->where('item_id', $itemId)
                            : $q->whereRaw('LOWER(item_label) = ?', [mb_strtolower($itemName)]);
                    })->exists();
            }

            $competencies[$key]['items'][] = [
                'item'      => $itemName,
                'dimension' => $dimension,
                'weight'    => $row['weight'] ?? 1.0,
                'already_present' => $alreadyPresent,
                // TARGET if the tenant already has this skill; HOLDING otherwise,
                // with the text kept exactly as the customer wrote it.
                'resolves_to' => $itemId,
                'state'     => $alreadyPresent ? 'ALREADY_PRESENT'
                                : ($itemId === null ? 'HELD_AS_LABEL' : 'TARGET'),
            ];

            // Counted only if it would actually be created.
            if (!$alreadyPresent) {
                $itemId === null ? $held++ : $resolved++;
                $byDimension[$dimension]['items']++;
                if ($itemId !== null) {
                    $byDimension[$dimension]['resolved']++;
                }
            }
        }

        $totalItems = $resolved + $held;
        $newCompetencies = count(array_filter($competencies, fn ($c) => !$c['already_exists']));

        // ── THE WRITE, IF THIS IS THE WRITE ─────────────────────────────────
        $created = ['competencies' => 0, 'items' => 0];
        if ($write) {
            if ($badShape !== []) {
                // A malformed file is refused ENTIRELY. Importing the good rows
                // of a broken file is the half-import this design rejects.
                return response()->json([
                    'status'  => 0,
                    'message' => count($badShape) . ' row(s) are malformed. Nothing was imported.',
                    'rejected_rows' => $badShape,
                ], 422);
            }

            DB::transaction(function () use ($competencies, $tenant, $identity, &$created) {
                foreach ($competencies as $key => $comp) {
                    // ALREADY EXISTING IS REUSED, NEVER DUPLICATED - a second copy
                    // would manufacture the ambiguity G-DATA-11 closed.
                    $cid = DB::table('competency')
                        ->where('sub_institute_id', $tenant)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($comp['name'])])
                        ->value('id');

                    if (!$cid) {
                        $cid = DB::table('competency')->insertGetId([
                            'sub_institute_id'    => $tenant,
                            'code'                => 'IMP-' . substr(md5($key), 0, 10),
                            'name'                => $comp['name'],
                            'description'         => 'Imported from a customer framework.',
                            'competency_type'     => 'imported',
                            'criticality'         => 'medium',
                            'requires_assessment' => 1,
                            'status'              => 'active',
                            'version'             => 1,
                            'created_by'          => $identity['user_id'],
                            'created_at'          => now(),
                            'updated_at'          => now(),
                        ]);
                        $created['competencies']++;
                    }

                    foreach ($comp['items'] as $item) {
                        // The SAME flag the dry run reported. Not re-derived -
                        // a second derivation is a second chance to disagree.
                        if ($item['already_present']) {
                            continue;
                        }

                        DB::table('competency_kasba_item')->insert([
                            'sub_institute_id' => $tenant,
                            'competency_id'    => $cid,
                            'kasba_type'       => $item['dimension'],
                            // TARGET where the name resolved; otherwise HELD with
                            // the customer's wording EXACTLY as they wrote it.
                            'item_id'          => $item['resolves_to'],
                            'item_label'       => $item['resolves_to'] === null ? $item['item'] : null,
                            'weight'           => $item['weight'],
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                        $created['items']++;
                    }
                }
            });
        }

        return response()->json(['status' => 1, 'data' => [
            'written'      => $write,
            'created'      => $write ? $created : null,
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
            'note' => $write
                ? 'Imported in one transaction. Items that did not match your skill library are '
                  . 'HELD as labels with your wording kept.'
                : 'NOTHING HAS BEEN WRITTEN. Items that do not match your skill library are '
                    . 'HELD as labels with your wording kept - they are not dropped and not '
                    . 'renamed. Most items in a real framework arrive this way, which is the '
                    . 'normal first state.',
        ]]);
    }
}
