<?php

namespace App\Domain\Intelligence\Detectors\Platform;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;

/**
 * AI policy coverage: active policies of THIS organisation that are assigned to nothing, so they govern nothing. Counts only. Needs `view` on /ai/policies.
 *
 * SOURCE (verified: the 2026_09_19 migration and AiPolicyController): `ai_policies` (sub_institute_id, status, require_acknowledgement) and
 * `ai_policy_assignments` (policy_id, status, sub_institute_id). The controller states the rule this applies: "a policy with no assignment governs nothing".
 * Policies with a NULL organisation are platform-wide defaults the organisation cannot edit; they are not assessed here. An assignment counts when it is active
 * and belongs to this organisation or to nobody in particular.
 *
 * ACKNOWLEDGEMENTS ARE DELIBERATELY NOT REPORTED. `ai_policy_acknowledgements` exists, but no code in this application writes to it (the only reference is the
 * command that removes seeded examples), so "no acknowledgements" would be true for every tenant and would be a finding invented from a feature that does not
 * exist yet. The evidence states that acknowledgements are not recorded; it never counts them, never reads that table, and never names a person.
 */
final class GovernanceDetector extends PlatformDetector
{
    private const LINK = '/ai/policies';

    public function id(): string
    {
        return 'platform.governance';
    }

    public function title(): string
    {
        return 'AI policy coverage';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_AI_POLICIES];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny([PlatformAccess::LINK_AI_POLICIES]);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('ai_policies', ['id', 'sub_institute_id', 'status', 'require_acknowledgement'])
            || ! $this->canRead('ai_policy_assignments', ['policy_id', 'status', 'sub_institute_id'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'AI policies are not available on this installation.');
        }

        $tenant = $caller->tenantId;
        $active = fn () => $this->db->table('ai_policies as p')->where('p.sub_institute_id', $tenant)->where('p.status', 1);

        $total = (int) $active()->count();
        $coverage = Coverage::of($total, null, 'active AI policies of this organisation', $this->thresholds->int('governance_min_policies'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $unassigned = fn () => $active()->whereNotExists(function ($sub) use ($tenant) {
            $sub->from('ai_policy_assignments as a')->selectRaw('1')->whereColumn('a.policy_id', 'p.id')->where('a.status', 1)
                ->where(function ($q) use ($tenant) {
                    $q->where('a.sub_institute_id', $tenant)->orWhereNull('a.sub_institute_id');
                });
        });
        $count = (int) $unassigned()->count();
        if ($count === 0) {
            return DetectorResult::ok($this, [], $coverage, $asOf);
        }

        $needAck = (int) $active()->where('p.require_acknowledgement', 1)->count();
        $ids = $unassigned()->orderBy('p.id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('p.id')->all();
        $evidence = [
            Evidence::metric('Active AI policies', $total, 'policies', 'ai_policies'),
            Evidence::metric('Active policies assigned to nothing', $count, 'policies', 'ai_policy_assignments'),
            Evidence::metric('Active policies that ask for acknowledgement', $needAck, 'policies'),
            Evidence::metric('Policy acknowledgements', 'not recorded by any G2G screen, so not assessed'),
        ];
        array_push($evidence, ...$this->entities($caller, 'Unassigned AI policy', 'ai_policy', $ids));

        return DetectorResult::ok($this, [new Insight(
            $this->key($caller, 'unassigned', $clock->today()), $this->id(), $this->domain(), 'governance', 'attention',
            sprintf('%d AI polic%s apply to nothing', $count, $count === 1 ? 'y does not' : 'ies do not'),
            sprintf('%d of %d active AI policies have no active assignment (to a module, department, role, course or the whole organisation).', $count, $total),
            'A policy with no assignment governs nothing: people using AI are not bound by it, whatever it says.',
            $evidence, $coverage, $asOf, 'Assign each policy to where it should apply, or retire it.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
        )], $coverage, $asOf);
    }
}
