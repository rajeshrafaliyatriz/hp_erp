<?php

namespace App\Support;

/**
 * The modules an admin may pick a dedicated email sender profile for, via
 * Account Settings → Email & SMS. A plain PHP constant list, not a DB table —
 * deliberately not `ai_modules` (that catalogue is AI-Stack-flavored,
 * carries `capabilities`/`registry_keys` columns irrelevant here, and has no
 * GTM entry at all) and not `RequirePlatformRight::DECENTRALIZED_MODULES`
 * (a different, unrelated authorization-scoping list). Each key below is a
 * module confirmed, by reading the real code, to actually send email today
 * — see the gap-closure plan's own audit for the file:line evidence. One
 * line to extend if a new module gains a real send site later.
 */
final class MailSenderModules
{
    /** The tenant-wide catch-all - every send site falls back to this when no module-specific profile exists. */
    public const DEFAULT = 'default';

    /** @var array<string, string> module_key => human label, in picker display order. */
    public const OPTIONS = [
        self::DEFAULT => 'Organisation default (everything else)',
        'talent_recruitment' => 'Talent Management — Hiring',
        'crm' => 'CRM — Sales & Support',
        'gtm_outreach' => 'GTM — Sales Outreach',
        'hrit_management' => 'HR — Leave & People Notifications',
        'ai_reports' => 'AI — Emailed Reports',
        'report_builder' => 'Reports — Scheduled Deliveries',
    ];

    public static function isValid(string $moduleKey): bool
    {
        return array_key_exists($moduleKey, self::OPTIONS);
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function forFrontend(): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys(self::OPTIONS),
            array_values(self::OPTIONS),
        );
    }
}
