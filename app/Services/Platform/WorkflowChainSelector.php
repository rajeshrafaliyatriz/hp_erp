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
 * THE SELECTION RULE, AND WHY IT REFUSES TO GUESS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `flow_key` is deliberately not unique — several chains at one point is the
 * design, chosen between by `condition`. But `condition` is stored verbatim and
 * NOTHING EVALUATES IT. There is no parser, and writing one is not this change.
 *
 * So:
 *
 *   1. Active chains with an EMPTY condition always apply. Lowest id wins, so the
 *      answer is stable rather than dependent on row order.
 *   2. If every active chain carries a condition, NONE is selected and the tenant
 *      falls back to its module settings. Picking one arbitrarily would be the
 *      silent-wrong-approver failure this whole piece of work exists to prevent —
 *      a chain that says "when amount > 10000" must not quietly govern every
 *      request regardless of amount.
 *   3. Drafts and disabled chains never apply. A draft governs nothing; that is
 *      what makes it a draft.
 *
 * `reason` is returned alongside so the console can explain itself rather than
 * showing a chain that looks active and is not.
 */
class WorkflowChainSelector
{
    private const TABLE = 'g2g_platform_workflows';

    /**
     * The chain that governs this point for this tenant.
     *
     * @return array{chain: ?object, reason: string}
     */
    public function select(int $tenantId, string $flowKey): array
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

        $unconditional = $active->first(fn ($row) => trim((string) $row->condition) === '');

        if ($unconditional === null) {
            // Rule 2. Every active chain is conditional and conditions are never
            // evaluated, so none of them can honestly be said to apply.
            return ['chain' => null, 'reason' => 'all_chains_conditional'];
        }

        return ['chain' => $unconditional, 'reason' => 'selected'];
    }

    /**
     * Whether one stored chain is the one that would actually be used.
     *
     * The console asks this per row so it can mark the others as shadowed rather
     * than letting three "active" chains all look equally in force.
     */
    public function isEffective(int $tenantId, string $flowKey, int $workflowId): bool
    {
        $selected = $this->select($tenantId, $flowKey)['chain'];

        return $selected !== null && (int) $selected->id === $workflowId;
    }

    /**
     * Why a stored chain is not the effective one, in a sentence for the screen.
     *
     * Returns null when it IS the effective one.
     */
    public function ineffectiveReason(int $tenantId, string $flowKey, object $row): ?string
    {
        if ($row->status !== 'active') {
            return $row->status === 'draft'
                ? 'A draft governs nothing. Activate it to put it in force.'
                : 'Disabled.';
        }

        if (trim((string) $row->condition) !== '') {
            return 'This chain has a condition, and conditions are not evaluated yet — '
                . 'so it never applies. Clear the condition to make it the chain for this point.';
        }

        if ($this->isEffective($tenantId, $flowKey, (int) $row->id)) {
            return null;
        }

        return 'Another unconditional chain on this point was created first and takes precedence.';
    }
}
