<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which platform chain, if any, governs a workflow point for a tenant right now.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS IS ITS OWN CLASS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Two readers need the identical answer and must never disagree:
 *
 *   LeaveApprovalWorkflow::chainFor()   decides which ladder a request walks
 *   WorkflowController::index()          tells the console which chain is effective
 *
 * Putting the rule on the leave service would make the platform console depend on
 * HRMS. Putting it in the controller would give the engine a second copy that
 * drifts. So it lives here, once — the same argument `config/platform_services.php`
 * makes in its own header about not porting the catalogue into TypeScript.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ROUND 2: CONDITIONS ARE NOW REAL, AND THE SELECTION RULE CHANGED WITH THEM
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `flow_key` is deliberately not unique — several chains at one point is the
 * design, chosen between by `condition`. Round 1 could not evaluate `condition` at
 * all — no parser existed — so only an UNCONDITIONAL chain could ever be selected,
 * and a point where every active chain carried a condition governed nothing.
 * `WorkflowConditionEvaluator` is that parser, and the rule is now:
 *
 *   1. CONDITIONAL chains are tried first, in id order — a chain saved earlier
 *      still wins a tie between two whose conditions both match. This is
 *      deliberately NOT one pass over every active chain in plain id order: an
 *      unconditional chain saved before a conditional one must not shadow it just
 *      for having a lower id, or "leave over 5 days needs extra sign-off,
 *      otherwise the normal chain applies" — the whole point of writing a
 *      condition at all — would depend on creation order rather than on what the
 *      condition says. Caught by actually running that exact scenario; see the
 *      note on `select()` itself.
 *   2. If none of the conditional chains matched, the first UNCONDITIONAL active
 *      chain, in id order, is the fallback.
 *   3. If neither produced a chain — including every case where `$context` is
 *      empty and every active chain is conditional — the tenant falls back to its
 *      module settings. `WorkflowConditionEvaluator::matches()` fails closed on a
 *      missing context field, so passing no context (as the console's own listing
 *      does — see `ineffectiveReason()`) means nothing conditional is ever
 *      "selected" in the abstract, only against a real record.
 *   4. Drafts and disabled chains never apply. A draft governs nothing; that is
 *      what makes it a draft.
 *
 * `reason` is returned alongside so the console can explain itself rather than
 * showing a chain that looks active and is not.
 */
class WorkflowChainSelector
{
    private const TABLE = 'g2g_platform_workflows';

    public function __construct(
        private readonly WorkflowConditionEvaluator $evaluator = new WorkflowConditionEvaluator(),
    ) {
    }

    /**
     * The chain that governs this point for this tenant, given what is known about the
     * specific record being decided (or nothing, for a listing with no one record —
     * see the class note on why that still fails closed correctly).
     *
     * @param  array<string, float|int>  $context
     * @return array{chain: ?object, reason: string}
     */
    public function select(int $tenantId, string $flowKey, array $context = []): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return ['chain' => null, 'reason' => 'not_installed'];
        }

        $active = DB::table(self::TABLE)
            ->where('sub_institute_id', $tenantId)
            ->where('flow_key', $flowKey)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($active->isEmpty()) {
            return ['chain' => null, 'reason' => 'no_active_chain'];
        }

        /*
         * CONDITIONAL CHAINS ARE TRIED BEFORE THE UNCONDITIONAL FALLBACK.
         *
         * Not simply "lowest id wins" over the whole set — that would make an
         * unconditional catch-all shadow every conditional chain created after it,
         * which is backwards from what "leave over 5 days needs extra sign-off,
         * otherwise the normal chain applies" means to whoever configured it. This
         * was caught by actually running it: an unconditional chain created first and
         * a "leave_days > 5" chain created second, tested with leave_days = 7,
         * selected the unconditional one — technically consistent with a pure id-order
         * rule, and not what any reasonable reading of that configuration means.
         *
         * So: every conditional chain is tried first, in id order (a tie between two
         * conditions that both match is still resolved by which was saved first — the
         * one case where id order is exactly the right tie-break, because nothing else
         * distinguishes two chains whose conditions are both satisfied). Only if none
         * of them match does the unconditional fallback apply, also in id order should
         * more than one somehow exist.
         */
        $conditional = $active->filter(fn ($row) => trim((string) $row->condition) !== '');
        $unconditional = $active->filter(fn ($row) => trim((string) $row->condition) === '');

        foreach ($conditional as $row) {
            if ($this->evaluator->matches($row->condition, $context)) {
                return ['chain' => $row, 'reason' => 'selected'];
            }
        }

        $fallback = $unconditional->first();

        if ($fallback !== null) {
            return ['chain' => $fallback, 'reason' => 'selected'];
        }

        // Every active chain is conditional and none matched — either because the
        // context does not satisfy them, or because no context was given at all.
        return ['chain' => null, 'reason' => 'no_matching_chain'];
    }

    /**
     * Whether one stored chain is the one that would actually be used.
     *
     * The console asks this per row so it can mark the others as shadowed rather
     * than letting three "active" chains all look equally in force.
     *
     * @param  array<string, float|int>  $context
     */
    public function isEffective(int $tenantId, string $flowKey, int $workflowId, array $context = []): bool
    {
        $selected = $this->select($tenantId, $flowKey, $context)['chain'];

        return $selected !== null && (int) $selected->id === $workflowId;
    }

    /**
     * Why a stored chain is not the effective one FOR THE GIVEN CONTEXT, in a sentence
     * for the screen. Returns null when it is.
     *
     * ── CALLED WITH NO CONTEXT FROM THE CONSOLE LISTING, ON PURPOSE ─────────────
     *
     * `WorkflowController::index()` is not evaluating any one real record — there is
     * none to evaluate. So a conditional chain correctly reports here that it cannot
     * be judged in the abstract, which is a different and more honest claim than
     * Round 1's "conditions are not evaluated yet" (now false) and different again
     * from a flat "ineffective" (which would wrongly suggest the chain never applies —
     * it may, for the right request; see the Preview action on the console, which
     * calls `select()` with a real sample context to answer that question directly).
     *
     * @param  array<string, float|int>  $context
     */
    public function ineffectiveReason(int $tenantId, string $flowKey, object $row, array $context = []): ?string
    {
        if ($row->status !== 'active') {
            return $row->status === 'draft'
                ? 'A draft governs nothing. Activate it to put it in force.'
                : 'Disabled.';
        }

        $condition = trim((string) $row->condition);

        if ($this->isEffective($tenantId, $flowKey, (int) $row->id, $context)) {
            return null;
        }

        if ($condition !== '') {
            return $context === []
                ? 'This chain only takes effect when its condition is met by the specific '
                    . 'request being decided — there is no single record here to judge it '
                    . 'against. Use Preview to test it against sample values.'
                : 'Its condition was not met by the sample values given.';
        }

        /*
         * This row IS unconditional and still lost — which now means one of two
         * things, and they read very differently:
         *
         *   - another unconditional chain, saved earlier, is the fallback instead
         *   - a CONDITIONAL chain's rule was satisfied and took precedence, which
         *     can happen regardless of which of the two has the lower id — see the
         *     ordering note on select().
         *
         * Re-deriving which of the two happened, rather than guessing from id order,
         * is what select() itself already did — so this asks it again with the same
         * context and reads the answer off the real winner.
         */
        $winner = $this->select($tenantId, $flowKey, $context)['chain'];

        if ($winner !== null && trim((string) $winner->condition) !== '') {
            return 'A conditional chain\'s rule was satisfied for this request and took '
                . 'precedence — conditional chains are always tried before the unconditional '
                . 'fallback.';
        }

        return 'Another unconditional chain, saved earlier, is the fallback for this point instead.';
    }
}
