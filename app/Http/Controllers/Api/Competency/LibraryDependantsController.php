<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L-06 — WHAT DEPENDS ON THIS LIBRARY ROW.
 *
 * The delete dialog used to say "Anything already mapped to it keeps its existing
 * record" — G-LIB-02's orphan admission, in the UI, telling the user orphans will
 * be created and never saying how many. This replaces the admission with a number.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE COUNT IS BY KEY, AND THAT IS NOT A STYLE CHOICE (G-LIB-09).
 *
 *   dependants of a skill, s_user_skill_jobrole:
 *     by KEY   + tenant ............  79,294
 *     by TITLE + tenant ............  80,531   (+1,237, +1.6%)
 *     by TITLE, NO tenant condition .479,623   (SIX TIMES reality)
 *
 *   A user told "deleting this affects 479,623 records" would never delete
 *   anything. A user told "1,237 more than are really there" would not notice.
 *   ONE IS OBVIOUSLY WRONG, THE OTHER IS QUIETLY WRONG, AND THE QUIET ONE IS
 *   WORSE.
 *
 * THE TENANT CONDITION LIVES INSIDE THE JOIN, not in a WHERE after it. On a LEFT
 * join a trailing WHERE silently converts it to an inner join; on an inner join
 * it is merely equivalent. Putting it in the ON clause is correct in both, which
 * is why it is the habit rather than the optimisation.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * THE DIVERGENCE IS REPORTED ONLY WHEN NON-ZERO. A permanent side-by-side would
 * make every deletion dialog carry a lecture about a defect the user cannot fix.
 * When it IS non-zero it is information somebody deleting master data should have:
 * it means duplicate titles exist, and something joining by name will behave
 * differently from this count.
 */
class LibraryDependantsController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * Where each library kind is depended upon.
     *
     * `key` is the FK column; `text` is the legacy name column that still exists
     * beside it (L-11's twelve sites). `text` is used ONLY to compute the
     * divergence, never to produce the headline number.
     *
     * @var array<string, array{table:string, rows:array<int,array{table:string,key:string,text:?string,label:string}>}>
     */
    private const DEPENDANTS = [
        'skill' => [
            'table' => 's_users_skills',
            'title' => 'title',
            'rows'  => [
                ['table' => 's_user_skill_jobrole', 'key' => 'skill_id', 'text' => 'skill', 'label' => 'job role mappings'],
                ['table' => 'competency_kasba_item', 'key' => 'item_id', 'text' => null, 'label' => 'competency items'],
            ],
        ],
        'jobrole' => [
            'table' => 's_user_jobrole',
            'title' => 'jobrole',
            'rows'  => [
                ['table' => 's_user_skill_jobrole', 'key' => 'jobrole_id', 'text' => 'jobrole', 'label' => 'skill mappings'],
                ['table' => 's_user_jobrole_task', 'key' => 'jobrole_id', 'text' => 'jobrole', 'label' => 'task mappings'],
                ['table' => 'jobrole_competency_map', 'key' => 'jobrole_id', 'text' => null, 'label' => 'competency requirements'],
                ['table' => 'course_jobrole_map', 'key' => 'jobrole_id', 'text' => null, 'label' => 'course mappings'],
            ],
        ],
    ];

    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'kind' => 'required|string|max:32',
            'id'   => 'required|integer|min:1',
        ]);

        $kind = $data['kind'];
        if (!isset(self::DEPENDANTS[$kind])) {
            return response()->json(['status' => 0, 'message' => "No dependant map for '$kind'."], 422);
        }

        $spec   = self::DEPENDANTS[$kind];
        $tenant = $identity['sub_institute_id'];

        // The row must be the caller's own before anything is counted for it.
        $subject = DB::table($spec['table'])
            ->where('id', $data['id'])
            ->where('sub_institute_id', $tenant)
            ->first(['id', $spec['title']]);

        if (!$subject) {
            return response()->json(['status' => 0, 'message' => 'Not found in your organisation.'], 404);
        }

        $titleValue = $subject->{$spec['title']} ?? null;

        $breakdown = [];
        $total = 0;
        $textTotal = 0;
        $anyTextComparable = false;

        foreach ($spec['rows'] as $dep) {
            if (!Schema::hasTable($dep['table']) || !Schema::hasColumn($dep['table'], $dep['key'])) {
                continue;   // the mapping table is not in this install
            }

            $byKey = DB::table($dep['table'])
                ->where($dep['key'], $subject->id)
                ->where('sub_institute_id', $tenant)
                ->count();

            $total += $byKey;
            $breakdown[] = ['label' => $dep['label'], 'count' => $byKey];

            // The divergence, computed only where a legacy text column survives.
            if ($dep['text'] !== null && $titleValue !== null && Schema::hasColumn($dep['table'], $dep['text'])) {
                $anyTextComparable = true;
                $textTotal += DB::table($dep['table'])
                    ->where($dep['text'], $titleValue)
                    ->where('sub_institute_id', $tenant)
                    ->count();
            } else {
                $textTotal += $byKey;
            }
        }

        $divergence = $anyTextComparable ? $textTotal - $total : 0;

        return response()->json([
            'status' => 1,
            'data'   => [
                'total'     => $total,
                'basis'     => 'key',
                'breakdown' => $breakdown,
                // Present ONLY when it is not zero. Null means "nothing to say",
                // which is what the dialog needs to decide whether to mention it.
                'divergence' => $divergence !== 0 ? [
                    'by_text'    => $textTotal,
                    'difference' => $divergence,
                    'reason'     => 'Rows sharing this name resolve differently where the product still joins by name.',
                ] : null,
            ],
        ]);
    }
}
