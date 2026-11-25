<?php

namespace App\Domain\Intelligence\Detectors\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\PeopleScope;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Query\Builder;

/**
 * CERTIFICATION EXPIRY: credentials that have lapsed or will lapse soon, for the people the caller may see.
 *
 * Sources: s_competency_certifications (expiry_date) and lms_certificates (expires_at; a certificate that was re-issued, `superseded_by`, no longer counts).
 *
 * THE EXPIRY DATE IS THE FACT; THE `status` COLUMN IS NOT. The product's own expiry sweep documents that `status` goes stale (rows past their date still read
 * `valid`). So this detector never reads `status` to decide that something lapsed. It reads `status` for one purpose only: to leave out a certification that
 * was REVOKED (withdrawn is not lapsing). A certification with no holder is left out (nobody to tell); the holder must be an active employee in scope.
 *
 * Buckets: lapsed (before today), due (within certification_due_days), soon (after that, within certification_soon_days).
 */
final class CertificationExpiryDetector extends AbstractHrDetector
{
    private const DEEP_LINK = '/module/talent-management/certifications';

    public function id(): string
    {
        return 'hr.certification_expiry';
    }

    public function title(): string
    {
        return 'Certification expiry';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_CERTIFICATIONS, Taxonomy::SURFACE_HR_DASHBOARD, Taxonomy::SURFACE_ME_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole(...self::PEOPLE_ROLES);
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        $hasCompetency = $this->canRead('s_competency_certifications', ['id', 'user_id', 'status', 'expiry_date', 'sub_institute_id', 'deleted_at']);
        $hasLms = $this->canRead('lms_certificates', ['id', 'user_id', 'status', 'expires_at', 'superseded_by', 'sub_institute_id', 'deleted_at']);
        if (! $hasCompetency && ! $hasLms) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Certification records are not available for this organisation.');
        }

        $today = $clock->today();
        $dueEnd = $clock->daysAhead($this->thresholds->int('certification_due_days'));
        $soonEnd = $clock->daysAhead($this->thresholds->int('certification_soon_days'));
        $self = $caller->scope() === 'self';

        // Both sources are measured the same way: one query each, an expiry compared as a plain 'Y-m-d' string (the datetime column gets the day's bounds).
        $sources = [];
        if ($hasCompetency) {
            $sources[] = ['table' => 'competency', 'query' => fn (): Builder => $this->db->table('s_competency_certifications as c')
                ->where('c.sub_institute_id', $caller->tenantId)->whereNull('c.deleted_at')->whereNotNull('c.user_id')
                ->whereRaw("LOWER(COALESCE(c.status, '')) <> 'revoked'")
                ->whereIn('c.user_id', PeopleScope::userIds($this->db, $caller)),
                'expiry' => 'c.expiry_date', 'user' => 'c.user_id', 'id' => 'c.id',
                'lapsed' => $today, 'dueEnd' => $dueEnd, 'soonEnd' => $soonEnd, 'type' => 'certification'];
        }
        if ($hasLms) {
            $sources[] = ['table' => 'lms', 'query' => fn (): Builder => $this->db->table('lms_certificates as c')
                ->where('c.sub_institute_id', $caller->tenantId)->whereNull('c.deleted_at')->whereNotNull('c.user_id')->whereNull('c.superseded_by')
                ->whereRaw("LOWER(COALESCE(c.status, '')) <> 'revoked'")
                ->whereIn('c.user_id', PeopleScope::userIds($this->db, $caller)),
                'expiry' => 'c.expires_at', 'user' => 'c.user_id', 'id' => 'c.id',
                'lapsed' => $today . ' 00:00:00', 'dueEnd' => $dueEnd . ' 23:59:59', 'soonEnd' => $soonEnd . ' 23:59:59', 'type' => 'lms_certificate'];
        }

        $holders = 0;
        $withExpiry = 0;
        $buckets = ['lapsed' => ['n' => 0, 'people' => 0], 'due' => ['n' => 0, 'people' => 0], 'soon' => ['n' => 0, 'people' => 0]];
        foreach ($sources as $s) {
            $col = $s['expiry'];
            $row = ($s['query'])()->selectRaw(
                "COUNT(*) AS total, SUM(CASE WHEN {$col} IS NOT NULL THEN 1 ELSE 0 END) AS with_expiry,"
                . " SUM(CASE WHEN {$col} < ? THEN 1 ELSE 0 END) AS lapsed,"
                . " SUM(CASE WHEN {$col} >= ? AND {$col} <= ? THEN 1 ELSE 0 END) AS due,"
                . " SUM(CASE WHEN {$col} > ? AND {$col} <= ? THEN 1 ELSE 0 END) AS soon,"
                . " COUNT(DISTINCT CASE WHEN {$col} < ? THEN {$s['user']} END) AS lapsed_people,"
                . " COUNT(DISTINCT CASE WHEN {$col} >= ? AND {$col} <= ? THEN {$s['user']} END) AS due_people,"
                . " COUNT(DISTINCT CASE WHEN {$col} > ? AND {$col} <= ? THEN {$s['user']} END) AS soon_people",
                [$s['lapsed'], $s['lapsed'], $s['dueEnd'], $s['dueEnd'], $s['soonEnd'], $s['lapsed'], $s['lapsed'], $s['dueEnd'], $s['dueEnd'], $s['soonEnd']],
            )->first();
            $holders += (int) ($row->total ?? 0);
            $withExpiry += (int) ($row->with_expiry ?? 0);
            $buckets['lapsed']['n'] += (int) ($row->lapsed ?? 0);
            $buckets['due']['n'] += (int) ($row->due ?? 0);
            $buckets['soon']['n'] += (int) ($row->soon ?? 0);
            // The same person may hold a certificate in both tables; the larger count is a lower bound of the union, so any suppression errs towards hiding.
            $buckets['lapsed']['people'] = max($buckets['lapsed']['people'], (int) ($row->lapsed_people ?? 0));
            $buckets['due']['people'] = max($buckets['due']['people'], (int) ($row->due_people ?? 0));
            $buckets['soon']['people'] = max($buckets['soon']['people'], (int) ($row->soon_people ?? 0));
        }

        if ($self && $holders === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'No certifications are recorded for you.');
        }
        $minimum = $self ? 1 : $this->thresholds->int('certification_min_records');
        $coverage = new Coverage($withExpiry, $holders, 'certifications with a holder in scope that carry an expiry date', $withExpiry >= $minimum,
            $withExpiry >= $minimum ? null : sprintf('Only %d certification(s) with an expiry date are recorded; at least %d are needed before expiry is assessed.', $withExpiry, $minimum));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $defs = [
            'lapsed' => ['high', 'have already expired', 'Expired credentials can leave people working without a valid qualification.', 'Renew or replace the expired certifications, or record why they no longer apply.'],
            'due' => ['attention', sprintf('expire within %d days', $this->thresholds->int('certification_due_days')), 'Renewal usually takes weeks; acting now avoids a lapse.', 'Start renewal for the certifications that expire soonest.'],
            'soon' => ['info', sprintf('expire within %d days', $this->thresholds->int('certification_soon_days')), 'Planning renewals early spreads the work and the cost.', 'Add the renewals to the training plan.'],
        ];
        $insights = [];
        foreach ($defs as $bucket => [$severity, $phrase, $why, $action]) {
            $n = $buckets[$bucket]['n'];
            if ($n === 0) {
                continue;
            }
            $evidence = [
                Evidence::metric(ucfirst($bucket === 'lapsed' ? 'Expired certifications' : 'Certifications ' . $phrase), $n, 'certifications', 'certifications'),
                Evidence::metric('Certifications with an expiry date', $withExpiry, 'certifications', 'certifications'),
                Evidence::window('Expiry window', $bucket === 'lapsed' ? '1000-01-01' : $today, $bucket === 'lapsed' ? $today : ($bucket === 'due' ? $dueEnd : $soonEnd)),
            ];
            if ($caller->mayReferenceIndividuals()) {
                foreach ($this->references($sources, $bucket, 10) as [$type, $id]) {
                    $evidence[] = Evidence::entity('Certification', $type, $id);
                }
            }
            $people = $buckets[$bucket]['people'];
            $insights[] = new Insight(
                key: $this->keyFor('expiry', $caller, $clock, $bucket),
                detector: $this->id(), domain: $this->domain(), category: 'certification_' . $bucket, severity: $severity,
                title: $self ? ($bucket === 'lapsed' ? 'A certification of yours has expired' : 'A certification of yours is about to expire')
                    : ($bucket === 'lapsed' ? 'Certifications have expired' : 'Certifications are about to expire'),
                whatHappened: $self ? sprintf('%d of your certification(s) %s.', $n, $phrase) : sprintf('%d certification(s) held by %d person(s) %s.', $n, $people, $phrase),
                whyItMatters: $why, evidence: $evidence, coverage: $coverage, asOf: $asOf, suggestedAction: $action,
                deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $people,
            );
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }

    /**
     * Up to `$limit` certification record references (kind + id) for one bucket, soonest expiry first, across both sources.
     *
     * @param  list<array<string, mixed>> $sources
     * @return list<array{0: string, 1: int}>
     */
    private function references(array $sources, string $bucket, int $limit): array
    {
        $out = [];
        foreach ($sources as $s) {
            $col = $s['expiry'];
            $q = ($s['query'])()->whereNotNull($col);
            match ($bucket) {
                'lapsed' => $q->where($col, '<', $s['lapsed']),
                'due' => $q->where($col, '>=', $s['lapsed'])->where($col, '<=', $s['dueEnd']),
                default => $q->where($col, '>', $s['dueEnd'])->where($col, '<=', $s['soonEnd']),
            };
            foreach ($q->orderBy($col)->limit($limit)->pluck($s['id']) as $id) {
                $out[] = [$s['type'], (int) $id];
            }
        }

        return array_slice($out, 0, $limit);
    }
}
