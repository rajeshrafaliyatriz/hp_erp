<?php

namespace App\Domain\Intelligence\Detectors\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Query\Builder;

/**
 * RECRUITMENT FUNNEL: where the open applications sit RIGHT NOW, and what is being left waiting.
 *
 * It is a SNAPSHOT of each application's current status, not a conversion funnel: the product keeps no status-transition history for applications, so a
 * rate "from shortlisted to hired" cannot be computed and none is claimed. Status text is free-form (the column was widened to a string), so it is
 * normalised with LOWER() before it is counted.
 *
 *   stale review - applications still "pending review" / "under review" for more than recruitment_stale_days since they were received
 *   offers lapsing - offers that were sent, whose expiry date has passed and that have NO OUTCOME: no accepted/declined row in talent_offer_acceptances
 *                    (the only place an acceptance is recorded) and an application that is not deleted, hired or rejected
 *   snapshot - the count at each current status (informational)
 *
 * Applicants are not employees, so this is organisation-wide recruitment data: only roles that work on recruitment (or oversee it) see it, and
 * executives and auditors see counts only. hr_executive is NOT among them: that role's data scope is the department, and applicants have none. Sources: talent_job_applications (no creation migration exists for it: it is guarded) and talent_offers.
 */
final class RecruitmentFunnelDetector extends AbstractHrDetector
{
    private const DEEP_LINK = '/module/talent-management/recruitment';
    private const REVIEW_STATUSES = ['pending review', 'under review'];

    public function id(): string
    {
        return 'hr.recruitment_funnel';
    }

    public function title(): string
    {
        return 'Recruitment pipeline';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_RECRUITMENT, Taxonomy::SURFACE_HR_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        // hr_executive is deliberately absent: its data scope is the DEPARTMENT, and applicants belong to no department, so organisation-wide
        // recruitment data is not theirs to read.
        return $caller->hasRole('hr_manager', 'administrator', 'recruiter', 'executive', 'auditor');
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        $hasDeleted = $this->canRead('talent_job_applications', ['id', 'status', 'applied_date', 'sub_institute_id', 'deleted_at']);
        if (! $hasDeleted && ! $this->canRead('talent_job_applications', ['id', 'status', 'applied_date', 'sub_institute_id'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Recruitment data is not available for this organisation.');
        }

        $window = $this->thresholds->int('recruitment_window_days');
        $start = $clock->daysAgo($window);
        $apps = fn (): Builder => $this->db->table('talent_job_applications as a')
            ->where('a.sub_institute_id', $caller->tenantId)
            ->when($hasDeleted, fn (Builder $q) => $q->whereNull('a.deleted_at'))
            ->where('a.applied_date', '>=', $start);

        $total = (int) $apps()->count();
        $minimum = $this->thresholds->int('recruitment_min_applications');
        $coverage = Coverage::of($total, null, sprintf('applications received in the last %d days', $window), $minimum);
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $byStatus = $apps()->selectRaw("LOWER(COALESCE(a.status, '')) AS st, COUNT(*) AS n")->groupByRaw("LOWER(COALESCE(a.status, ''))")
            ->orderByDesc('n')->limit(12)->get();
        $insights = [];
        $window_ev = Evidence::window('Applications received', $start, $clock->today());

        $staleBefore = $clock->daysAgo($this->thresholds->int('recruitment_stale_days'));
        $stale = fn (): Builder => $apps()->whereRaw("LOWER(COALESCE(a.status, '')) IN (?, ?)", self::REVIEW_STATUSES)->where('a.applied_date', '<', $staleBefore);
        $staleN = (int) $stale()->count();
        if ($staleN > 0) {
            $evidence = [
                Evidence::metric(sprintf('Applications waiting for review more than %d days', $this->thresholds->int('recruitment_stale_days')), $staleN, 'applications', 'talent_job_applications'),
                Evidence::metric('Applications received in the period', $total, 'applications', 'talent_job_applications'),
                $window_ev,
            ];
            if ($caller->mayReferenceIndividuals()) {
                foreach ($stale()->orderBy('a.applied_date')->limit(10)->pluck('a.id') as $id) {
                    $evidence[] = Evidence::entity('Application', 'job_application', (int) $id);
                }
            }
            $insights[] = new Insight(
                key: $this->keyFor('stale_review', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'recruitment_stale_applications',
                severity: $staleN >= 10 ? 'high' : 'attention',
                title: 'Applications are waiting for review',
                whatHappened: sprintf('%d application(s) have been in review for more than %d days.', $staleN, $this->thresholds->int('recruitment_stale_days')),
                whyItMatters: 'Strong candidates accept other offers while an application sits unanswered.',
                evidence: $evidence, coverage: $coverage, asOf: $asOf,
                suggestedAction: 'Review or close the waiting applications.',
                deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: 'organization',
                surfaces: $this->surfaces(), population: $staleN,
            );
        }

        if ($this->canRead('talent_offers', ['id', 'status', 'expires_at', 'sub_institute_id'])) {
            // "Sent and past expiry with NO OUTCOME". talent_offers.status is an enum (draft|sent|rejected|expired) with no 'accepted': an acceptance is
            // recorded ONLY in talent_offer_acceptances (decision accepted|declined|pending), so an offer is excluded when a live acceptance row of this
            // tenant says accepted or declined (pending is still no outcome), and when its application is deleted or already hired / rejected.
            $hasAcceptances = $this->canRead('talent_offer_acceptances', ['offer_id', 'decision', 'sub_institute_id', 'deleted_at']);
            $tenant = $caller->tenantId;
            $lapsing = fn (): Builder => $this->db->table('talent_offers as o')->where('o.sub_institute_id', $caller->tenantId)
                ->whereRaw("LOWER(COALESCE(o.status, '')) = 'sent'")->whereNotNull('o.expires_at')->where('o.expires_at', '<', $clock->today())
                ->when($hasAcceptances, fn (Builder $q) => $q->whereNotExists(function ($sub) use ($tenant) {
                    $sub->selectRaw('1')->from('talent_offer_acceptances as ac')
                        ->whereColumn('ac.offer_id', 'o.id')->where('ac.sub_institute_id', $tenant)->whereNull('ac.deleted_at')
                        ->whereRaw("LOWER(COALESCE(ac.decision, '')) IN ('accepted', 'declined')");
                }))
                ->whereNotExists(function ($sub) use ($tenant, $hasDeleted) {
                    $sub->selectRaw('1')->from('talent_job_applications as ap')
                        ->whereColumn('ap.id', 'o.application_id')->where('ap.sub_institute_id', $tenant)
                        ->where(function ($w) use ($hasDeleted) {
                            $w->whereRaw("LOWER(COALESCE(ap.status, '')) IN ('hired', 'rejected')");
                            if ($hasDeleted) {
                                $w->orWhereNotNull('ap.deleted_at');
                            }
                        });
                });
            $lapsingN = (int) $lapsing()->count();
            if ($lapsingN > 0) {
                $evidence = [Evidence::metric('Offers sent and past their expiry date', $lapsingN, 'offers', 'talent_offers')];
                if ($caller->mayReferenceIndividuals()) {
                    foreach ($lapsing()->orderBy('o.expires_at')->limit(10)->pluck('o.id') as $id) {
                        $evidence[] = Evidence::entity('Offer', 'job_offer', (int) $id);
                    }
                }
                $insights[] = new Insight(
                    key: $this->keyFor('offers_lapsed', $caller, $clock),
                    detector: $this->id(), domain: $this->domain(), category: 'recruitment_offers_lapsed', severity: 'attention',
                    title: 'Offers are past their expiry date with no outcome',
                    whatHappened: sprintf('%d offer(s) were sent and their expiry date has passed.', $lapsingN),
                    whyItMatters: 'An offer that lapses without an answer leaves the position open and the candidate in doubt.',
                    evidence: $evidence, coverage: $coverage, asOf: $asOf,
                    suggestedAction: 'Contact the candidates, then extend, withdraw or close the offers.',
                    deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: 'organization',
                    surfaces: $this->surfaces(), population: $lapsingN,
                );
            }
        }

        $snapshot = [Evidence::metric('Applications received in the period', $total, 'applications', 'talent_job_applications'), $window_ev];
        foreach ($byStatus as $row) {
            $snapshot[] = Evidence::metric('Currently ' . ($row->st === '' ? '(no status)' : $row->st), (int) $row->n, 'applications', 'talent_job_applications');
        }
        $insights[] = new Insight(
            key: $this->keyFor('snapshot', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'recruitment_snapshot', severity: 'info',
            title: 'Where applications are right now',
            whatHappened: sprintf('%d application(s) were received in the last %d days; the evidence shows how many sit at each current status.', $total, $window),
            whyItMatters: 'A single view of the pipeline shows where candidates are accumulating. It is a snapshot of current status, not a conversion rate.',
            evidence: $snapshot, coverage: $coverage, asOf: $asOf, suggestedAction: null,
            deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: 'organization',
            surfaces: $this->surfaces(), population: $total,
        );

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }
}
