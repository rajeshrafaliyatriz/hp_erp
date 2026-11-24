<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Support\DetectionClock;

/**
 * A deterministic rule that turns records into insights.
 *
 * CONTRACT (every implementation, enforced by review and by the tenant-isolation tests):
 *   1. `authorizes()` decides whether this caller may see this kind of insight AT ALL. It may check roles and menu rights; it must not read other people's rows.
 *      The service never calls `detect()` for a caller the detector does not authorise: authorization happens on the server, before any data is read.
 *   2. `detect()` reads through the injected connection only, filters `sub_institute_id` = the caller's tenant on EVERY table it touches (a table without a
 *      tenant column is reached only through its parent), applies the caller's people scope (PeopleScope) and the canonical active-employee rule
 *      (ActiveEmployees), and never selects a name, e-mail, phone, free-text, payload, salary, bank, identity-number or performance column.
 *   3. It is read-only: no insert/update/delete, no dispatch, no cache write, no external call, no LLM call. Detection is rules; narration is separate.
 *   4. It computes every date boundary from the injected DetectionClock (never `now()` in SQL) so results are reproducible and portable across drivers.
 *   5. It returns `insufficient` (with a Coverage) when its PROVISIONAL minimum is not met, instead of an empty or zero result.
 *   6. It sets `Insight::population` to the number of distinct people a figure is drawn from, so the small-group rule can apply.
 */
interface InsightDetector
{
    /** Stable id, e.g. `hr.certification_expiry`. */
    public function id(): string;

    /** Short human title for the Evidence & coverage view. */
    public function title(): string;

    /** One of Taxonomy::DOMAINS. */
    public function domain(): string;

    /** @return list<string> Taxonomy::SURFACES this detector's insights belong on. */
    public function surfaces(): array;

    public function authorizes(IntelligenceCaller $caller): bool;

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult;
}
