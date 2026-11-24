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
 * Integrations whose saved status is `error`, or saved but not configured, or configured and not tested for a long time. Needs `view` on a Platform Services
 * module row (PlatformAccess).
 *
 * SOURCE (verified: the 2026_09_28 migration and IntegrationController): `g2g_integration_credentials` (provider_key, status configured|not_configured|error|
 * unknown, last_tested_at). A provider that was never saved has no row, and providers read from the environment or other tables are not in this table, so this
 * detector reports on SAVED rows only and says so; it never claims that an unlisted provider is "not connected".
 *
 * SECRETS. The table holds the encrypted credential blob and the last test message. THIS DETECTOR SELECTS ONLY `provider_key`, `status` and `last_tested_at`
 * (the tenant column is used in the WHERE only). The credential blob, the test message and the audit names are never selected, never held and never
 * serialised; the tests record the executed SQL to prove it.
 */
final class IntegrationStatusDetector extends PlatformDetector
{
    private const TABLE = 'g2g_integration_credentials';
    private const LINK = '/platform-services/integration';
    private const MAX_ROWS = 200;
    private const MAX_NAMED = 8;
    /** The only columns this detector may select. */
    public const SELECTED_COLUMNS = ['provider_key', 'status', 'last_tested_at'];

    public function id(): string
    {
        return 'platform.integration_status';
    }

    public function title(): string
    {
        return 'Integration status';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_PLATFORM_INTEGRATIONS];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(PlatformAccess::platformModuleLinks());
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead(self::TABLE, ['id', 'sub_institute_id', ...self::SELECTED_COLUMNS])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Integration status is not available on this installation.');
        }

        $rows = $this->db->table(self::TABLE)->where('sub_institute_id', $caller->tenantId)
            ->orderBy('provider_key')->limit(self::MAX_ROWS)->get(self::SELECTED_COLUMNS);

        $coverage = Coverage::of($rows->count(), null, 'integrations this organisation has saved settings for', $this->thresholds->int('integrations_min'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $asOf);
        }

        $untestedDays = $this->thresholds->int('integration_untested_days');
        $untestedBefore = $clock->dateTimeDaysAgo($untestedDays);
        $errors = [];
        $notConfigured = [];
        $untested = [];
        $configured = 0;
        foreach ($rows as $row) {
            $status = (string) $row->status;
            $key = self::safeKey($row->provider_key, 'integration');
            if ($status === 'error') {
                $errors[$key] = $row->last_tested_at;
            } elseif ($status === 'configured') {
                $configured++;
                if ($row->last_tested_at === null || (string) $row->last_tested_at < $untestedBefore) {
                    $untested[] = $key;
                }
            } else {
                $notConfigured[] = $key;   // not_configured, unknown, or any value this version does not know: never treated as healthy
            }
        }

        $insights = [];
        $today = $clock->today();
        $base = [Evidence::metric('Integrations with saved settings', $rows->count(), 'integrations', self::TABLE), Evidence::metric('Configured', $configured, 'integrations')];

        if ($errors !== []) {
            $evidence = [...$base, Evidence::metric('Failed their last test', count($errors), 'integrations')];
            foreach (array_slice($errors, 0, self::MAX_NAMED, true) as $key => $tested) {
                $evidence[] = Evidence::metric('In error: ' . $key, $tested === null ? 'never tested' : 'last tested ' . substr((string) $tested, 0, 10));
            }
            $insights[] = new Insight(
                $this->key($caller, 'error', $today), $this->id(), $this->domain(), 'integrations', 'high',
                sprintf('%d integration(s) failed their last test', count($errors)),
                sprintf('%d saved integration(s) are in an error state.', count($errors)),
                'Anything that depends on a failing integration (messages, webhooks, connected services) is not working until its settings are fixed and re-tested.',
                $evidence, $coverage, $asOf, 'Open each integration, correct its settings and run the test again.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
            );
        }
        if ($notConfigured !== []) {
            $evidence = [...$base, Evidence::metric('Saved but not configured', count($notConfigured), 'integrations')];
            foreach (array_slice($notConfigured, 0, self::MAX_NAMED) as $key) {
                $evidence[] = Evidence::metric('Not configured: ' . $key, 'saved without working settings');
            }
            $insights[] = new Insight(
                $this->key($caller, 'not_configured', $today), $this->id(), $this->domain(), 'integrations', 'attention',
                sprintf('%d integration(s) are saved but not configured', count($notConfigured)),
                sprintf('%d saved integration(s) are not configured or have an unknown status. Providers that were never saved are not listed here.', count($notConfigured)),
                'An integration that is saved without working settings does nothing, and the screens that rely on it will not say why.',
                $evidence, $coverage, $asOf, 'Complete or remove these integrations.', self::LINK, 'measured', $this->audience(), $this->surfaces(), null,
            );
        }
        if ($untested !== []) {
            $evidence = [...$base, Evidence::metric("Configured and not tested in {$untestedDays} days (or never)", count($untested), 'integrations')];
            foreach (array_slice($untested, 0, self::MAX_NAMED) as $key) {
                $evidence[] = Evidence::metric('Untested: ' . $key, 'no recent test');
            }
            $insights[] = new Insight(
                $this->key($caller, 'untested', $today), $this->id(), $this->domain(), 'integrations', 'info',
                sprintf('%d configured integration(s) have not been tested recently', count($untested)),
                sprintf('%d integration(s) are configured but were not tested in the last %d days, or never.', count($untested), $untestedDays),
                'A configured status only records the last test; credentials can expire without anyone noticing until the test is run again.',
                $evidence, $coverage, $asOf, 'Run the test on each integration.', self::LINK, 'rule_based', $this->audience(), $this->surfaces(), null,
            );
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }
}
