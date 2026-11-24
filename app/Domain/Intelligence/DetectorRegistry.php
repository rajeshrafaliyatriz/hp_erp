<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Detectors\Commercial\CustomerRenewalDetector;
use App\Domain\Intelligence\Detectors\Commercial\DealHealthDetector;
use App\Domain\Intelligence\Detectors\Commercial\InvoiceAttentionDetector;
use App\Domain\Intelligence\Detectors\Commercial\PipelineAttentionDetector;
use App\Domain\Intelligence\Detectors\Commercial\SignalEvidenceQualityDetector;
use App\Domain\Intelligence\Detectors\Commercial\SignalYieldDetector;
use App\Domain\Intelligence\Detectors\Cross\LearningCapabilityGapDetector;
use App\Domain\Intelligence\Detectors\Cross\RenewalInvoiceOverlapDetector;
use App\Domain\Intelligence\Detectors\Hr\AttendanceHealthDetector;
use App\Domain\Intelligence\Detectors\Hr\CapabilityDevelopmentDetector;
use App\Domain\Intelligence\Detectors\Hr\CertificationExpiryDetector;
use App\Domain\Intelligence\Detectors\Hr\LearningAssignmentsDetector;
use App\Domain\Intelligence\Detectors\Hr\LeaveAttentionDetector;
use App\Domain\Intelligence\Detectors\Hr\OnboardingProbationDetector;
use App\Domain\Intelligence\Detectors\Hr\OrganisationStructureDetector;
use App\Domain\Intelligence\Detectors\Hr\RecruitmentFunnelDetector;
use App\Domain\Intelligence\Detectors\Hr\TaskWorkflowDetector;
use App\Domain\Intelligence\Detectors\Platform\AgentRunHealthDetector;
use App\Domain\Intelligence\Detectors\Platform\AiUsageDetector;
use App\Domain\Intelligence\Detectors\Platform\EventDeliveryDetector;
use App\Domain\Intelligence\Detectors\Platform\GovernanceDetector;
use App\Domain\Intelligence\Detectors\Platform\IntegrationStatusDetector;
use App\Domain\Intelligence\Detectors\Platform\SchedulerReliabilityDetector;
use App\Domain\Intelligence\Support\Thresholds;
use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * The fixed list of detectors (Phase 1: HR and commercial; then platform services, AI governance and Agentic AI). Adding one is a code change here, reviewed like any other: there is no runtime registration, no plug-in discovery and
 * no configuration that can switch a detector on. Payroll and performance detectors do not exist and cannot be added by configuration.
 */
final class DetectorRegistry
{
    /** @var ?Closure(ConnectionInterface, Thresholds): list<InsightDetector> TEST SEAM ONLY: tests substitute stub detectors; production never sets it. */
    private static ?Closure $override = null;

    /** @return list<InsightDetector> */
    public static function resolve(ConnectionInterface $db, Thresholds $thresholds): array
    {
        return self::$override !== null ? (self::$override)($db, $thresholds) : self::forConnection($db, $thresholds);
    }

    /** @return list<InsightDetector> */
    public static function forConnection(ConnectionInterface $db, Thresholds $thresholds): array
    {
        return [
            new AttendanceHealthDetector($db, $thresholds),
            new LeaveAttentionDetector($db, $thresholds),
            new CertificationExpiryDetector($db, $thresholds),
            new RecruitmentFunnelDetector($db, $thresholds),
            new OnboardingProbationDetector($db, $thresholds),
            new OrganisationStructureDetector($db, $thresholds),
            new TaskWorkflowDetector($db, $thresholds),
            new LearningAssignmentsDetector($db, $thresholds),
            new CapabilityDevelopmentDetector($db, $thresholds),
            new SignalEvidenceQualityDetector($db, $thresholds),
            new SignalYieldDetector($db, $thresholds),
            new DealHealthDetector($db, $thresholds),
            new PipelineAttentionDetector($db, $thresholds),
            new CustomerRenewalDetector($db, $thresholds),
            new InvoiceAttentionDetector($db, $thresholds),
            new SchedulerReliabilityDetector($db, $thresholds),
            new IntegrationStatusDetector($db, $thresholds),
            new EventDeliveryDetector($db, $thresholds),
            new AiUsageDetector($db, $thresholds),
            new GovernanceDetector($db, $thresholds),
            new AgentRunHealthDetector($db, $thresholds),
            new LearningCapabilityGapDetector($db, $thresholds),
            new RenewalInvoiceOverlapDetector($db, $thresholds),
        ];
    }

    /** TEST SEAM ONLY. Pass null to restore the real list. */
    public static function overrideForTests(?Closure $factory): void
    {
        self::$override = $factory;
    }
}
