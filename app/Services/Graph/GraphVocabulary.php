<?php

namespace App\Services\Graph;

/**
 * Phase 7.1 — G2G's first declared graph vocabulary.
 *
 * G2G had no vocabulary file at all before this: `Neo4jProjector` MERGEs
 * labels directly, with nothing to validate against. This mirrors
 * hpbrain_backend's `Domain\Graph\GraphVocabulary` — same family NAMES where
 * the concept is shared (`organization`), a new `competency` family for the
 * JobRole/Competency/KasbaItem layer EB and K-12 don't have.
 *
 * SCOPED TO WHAT NEO4JPROJECTOR ACTUALLY WRITES TODAY. The live graph also
 * carries older REQUIRES_SKILL/KNOWLEDGE/ABILITY/BEHAVIOUR/ATTITUDE edges
 * from a load process nobody can find or reproduce (see Neo4jProjector's own
 * docblock, "STILL NOT COVERED"). Declaring those here would assert they are
 * a maintained part of the graph, which is false — they are a separate,
 * already-flagged finding, not vocabulary.
 */
final class GraphVocabulary
{
    public const FAMILY_ORGANIZATION = 'organization';

    /** JobRole / Competency / KasbaItem — the KASBA-driven role-competency layer. */
    public const FAMILY_COMPETENCY = 'competency';

    public const FAMILY_PEOPLE = 'people';

    /**
     * THE canonical KASBA enum (roadmap §4.5, migration
     * 2026_08_04_120000_align_s_skill_matrix_with_live_schema.php:30, where it
     * is a private, migration-local const and therefore not referenceable
     * from here — this is the first reusable copy). Order matches that
     * migration's.
     *
     * CompetencyDefinitionController::KASBA and
     * FrameworkImportController::DIMENSIONS are the same five, kept as
     * separate re-declarations before this existed — both now point here
     * instead. LibraryController::KASA_TYPES is deliberately NOT one of
     * these: it is four, not five, because 'skill' alone has its own
     * differently-shaped route (jobrole/jobrole-task siblings the other four
     * don't have), not a dropped dimension.
     *
     * hpbrain_backend's KasbaService.php hardcodes this same five-value set
     * independently (no shared vendor path between separate Laravel apps
     * makes that unavoidable) — kept in sync with this list BY HAND. If this
     * array changes, that file's copy must change with it.
     */
    public const KASBA_TYPES = ['skill', 'knowledge', 'ability', 'attitude', 'behaviour'];

    public const LABEL_FAMILY = [
        'Organization' => self::FAMILY_ORGANIZATION,
        'Department' => self::FAMILY_ORGANIZATION,
        'JobRole' => self::FAMILY_COMPETENCY,
        'Competency' => self::FAMILY_COMPETENCY,
        'KasbaItem' => self::FAMILY_COMPETENCY,
        // Phase 7.2 — the graph's first Person node, from the reporting line.
        'Person' => self::FAMILY_PEOPLE,
    ];

    /**
     * Relationship type => [human label, family, what produces it].
     *
     * Same shape as hpbrain_backend's GraphVocabulary::RELATIONSHIPS — a
     * provenance clause naming the actual projector method, not a generic
     * "connected to".
     */
    public const RELATIONSHIPS = [
        'has_kasba_item' => [
            'has KASBA item',
            self::FAMILY_COMPETENCY,
            'Neo4jProjector::projectCompetency() on competency.changed — one edge per resolved item_id in competency_kasba_item.',
        ],
        'requires_competency' => [
            'requires competency',
            self::FAMILY_COMPETENCY,
            'Neo4jProjector::projectJobroleCompetencyMap() on jobrole_competency_map.changed — requiredProficiency/isMandatory carried as edge properties, full-replace per the SQL source\'s own sync semantics.',
        ],
        // Phase 7.2 — reporting structure, the roadmap's canonical-source
        // decision: G2G's tbluser.reporting_manager_id (real cycle-detection
        // logic in ReportingLineValidator) over EB's empty, freestanding
        // hpbrain_reporting_structures table. Matches K-12's existing edge
        // name of the same shape (manager -> reports chain).
        'reports_to' => [
            'reports to',
            self::FAMILY_PEOPLE,
            'Neo4jProjector::projectReportingLine() on employee_reporting_line.changed — tbluser.reporting_manager_id, written only through ReportingLineController::applyOne().',
        ],
    ];

    public static function family(string $label): string
    {
        return self::LABEL_FAMILY[$label] ?? self::FAMILY_ORGANIZATION;
    }

    public static function isKnownLabel(string $label): bool
    {
        return isset(self::LABEL_FAMILY[$label]);
    }

    public static function isKnownRelationship(string $type): bool
    {
        return isset(self::RELATIONSHIPS[$type]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function relationship(string $type): array
    {
        return self::RELATIONSHIPS[$type] ?? [$type, self::FAMILY_ORGANIZATION, 'Not declared — needs a provenance clause before it is trusted.'];
    }

    /** @return string[] every family the client may filter by */
    public static function families(): array
    {
        return array_values(array_unique(self::LABEL_FAMILY));
    }
}
