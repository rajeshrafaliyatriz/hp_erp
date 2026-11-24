<?php

namespace Tests\Intelligence\Fixtures;

/**
 * Every fixture table the detectors and the service read, in one place: the people tables from IntelligenceTestCase, the HR, commercial and platform shapes, and
 * the profile table. Composes the existing fixture traits without editing them. Isolated in-memory SQLite only (see IntelligenceTestCase).
 *
 * Both HrTables and CommercialTables define `insertRow` with the same behaviour (a plain insert); the HR one is used.
 */
trait AllTables
{
    use HrTables, CommercialTables, PlatformTables {
        HrTables::insertRow insteadof CommercialTables;
    }

    protected function createAllTables(): void
    {
        $this->createPeopleTables();
        $this->createHrTables();
        $this->createCommercialTables();
        $this->createPlatformTables();
        ApiTables::createProfileTable();
    }
}
