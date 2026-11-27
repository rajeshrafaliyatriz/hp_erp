<?php

namespace App\Domain\Crm\Automation;

use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\Workflows\WorkflowCatalog;
use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;

/**
 * Creates the recommended workflows (LeadAutomationPresets) for ONE organisation, exactly as the Workflows screen would:
 * validated against the catalogue, stored as ordinary gtm_workflows rows, owned by the administrator named. They are stored as
 * DRAFTS unless told to activate - nothing runs until a person looks and switches them on. Re-running never duplicates a
 * workflow (matched by name within the tenant). It changes no other data and sends nothing.
 *
 * Two doors lead here and both end in createFrom(): install() (the console command, every preset) and installOne() (the
 * Workflows screen's recipe gallery, one preset by its key). installOne() has no way to activate: whatever the screen sends, the workflow
 * it makes is a draft.
 */
final class LeadAutomationInstaller
{
    /**
     * @param  array<string, mixed>  $options  see LeadAutomationPresets::all()
     * @return array{created: list<string>, existing: list<string>, invalid: array<string, list<string>>}
     */
    public static function install(int $tenant, int $adminUserId, array $options, bool $activate = false): array
    {
        self::guard($tenant, $adminUserId, $options);
        $out = ['created' => [], 'existing' => [], 'invalid' => []];
        foreach (LeadAutomationPresets::all($options) as $d) {
            $r = self::createFrom($tenant, $adminUserId, $d, $activate, 'lead-automation-install', true);
            match ($r['outcome']) {
                'existing' => $out['existing'][] = $d['name'],
                'invalid' => $out['invalid'][$d['name']] = $r['errors'],
                default => $out['created'][] = $d['name'],
            };
        }

        return $out;
    }

    /**
     * Adds ONE recommended workflow, always as a draft, and only when this organisation has no live (not archived) workflow of that name yet.
     * The same checks as install(): the owner must be an active administrator of the organisation.
     *
     * @param  array<string, mixed>  $options  see LeadAutomationPresets::all()
     * @return array{outcome: 'created'|'existing'|'invalid'|'unknown', id: ?int, errors: list<string>}
     */
    public static function installOne(int $tenant, int $adminUserId, array $options, string $key, string $via = 'recipe'): array
    {
        self::guard($tenant, $adminUserId, $options);
        foreach (LeadAutomationPresets::all($options) as $d) {
            if ($d['key'] === $key) {
                return self::createFrom($tenant, $adminUserId, $d, false, $via, false);
            }
        }

        return ['outcome' => 'unknown', 'id' => null, 'errors' => []];
    }

    /**
     * The workflow of each name in this organisation, by name. THE one place that decides what "already added" means, and it only ever looks inside the
     * organisation: another organisation's workflow of the same name is not this one's.
     *
     * @param  list<string>  $names
     * @return array<string, int> name => id (the oldest, when a name is used twice)
     */
    public static function existingIds(int $tenant, array $names, bool $archivedCounts): array
    {
        if ($names === []) {
            return [];
        }
        $q = DB::table('gtm_workflows')->where('sub_institute_id', $tenant)->whereIn('name', $names);
        if (! $archivedCounts) {
            $q->where('status', '!=', 'archived');
        }

        // the database may compare names without regard to case, so match the same way here
        $byLower = [];
        foreach ($q->orderByDesc('id')->get(['id', 'name']) as $row) {
            $byLower[mb_strtolower((string) $row->name)] = (int) $row->id;
        }
        $out = [];
        foreach ($names as $name) {
            if (isset($byLower[mb_strtolower($name)])) {
                $out[$name] = $byLower[mb_strtolower($name)];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    private static function guard(int $tenant, int $adminUserId, array $options): void
    {
        $profile = DB::table('tbluser as u')->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')->where('u.id', $adminUserId)->where('u.sub_institute_id', $tenant)->where('u.status', 1)
            ->first(['p.id', 'p.sub_institute_id', 'p.role_key', 'p.name']);
        if ($profile === null || RoleKey::fromProfile($profile) !== 'administrator') {
            throw new \InvalidArgumentException("User {$adminUserId} is not an active administrator of organisation {$tenant}: workflows run as an administrator, so one must own them.");
        }
        if (! DB::table('tbluser')->where('id', (int) ($options['support_user_id'] ?? 0))->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
            throw new \InvalidArgumentException('support_user_id must be an active user of this organisation (the person onboarding tickets are assigned to).');
        }
    }

    /**
     * @param  array<string, mixed>  $d  one preset
     * @return array{outcome: 'created'|'existing'|'invalid', id: ?int, errors: list<string>}
     */
    private static function createFrom(int $tenant, int $adminUserId, array $d, bool $activate, string $via, bool $archivedCounts): array
    {
        $existing = self::existingIds($tenant, [$d['name']], $archivedCounts)[$d['name']] ?? null;
        if ($existing !== null) {
            return ['outcome' => 'existing', 'id' => $existing, 'errors' => []];
        }
        $errors = WorkflowCatalog::validate($tenant, $d['trigger_event'], $d['conditions'], $d['actions']);
        if ($errors !== []) {
            return ['outcome' => 'invalid', 'id' => null, 'errors' => $errors];
        }
        $id = DB::table('gtm_workflows')->insertGetId([
            'sub_institute_id' => $tenant, 'name' => $d['name'], 'description' => $d['description'], 'trigger_event' => $d['trigger_event'],
            'conditions' => json_encode($d['conditions'], JSON_UNESCAPED_UNICODE), 'actions' => json_encode($d['actions'], JSON_UNESCAPED_UNICODE),
            'status' => $activate ? 'active' : 'draft', 'activated_at' => $activate ? now() : null, 'created_by' => $adminUserId, 'updated_by' => $adminUserId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        GtmAudit::record('gtm.workflow.created', $tenant, 'gtm_workflows', $id, $adminUserId, ['name' => $d['name'], 'trigger' => $d['trigger_event'], 'via' => $via]);

        return ['outcome' => 'created', 'id' => $id, 'errors' => []];
    }
}
