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
 */
final class LeadAutomationInstaller
{
    /**
     * @param  array<string, mixed>  $options  see LeadAutomationPresets::all()
     * @return array{created: list<string>, existing: list<string>, invalid: array<string, list<string>>}
     */
    public static function install(int $tenant, int $adminUserId, array $options, bool $activate = false): array
    {
        $profile = DB::table('tbluser as u')->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')->where('u.id', $adminUserId)->where('u.sub_institute_id', $tenant)->where('u.status', 1)
            ->first(['p.id', 'p.sub_institute_id', 'p.role_key', 'p.name']);
        if ($profile === null || RoleKey::fromProfile($profile) !== 'administrator') {
            throw new \InvalidArgumentException("User {$adminUserId} is not an active administrator of organisation {$tenant}: workflows run as an administrator, so one must own them.");
        }
        if (! DB::table('tbluser')->where('id', (int) ($options['support_user_id'] ?? 0))->where('sub_institute_id', $tenant)->where('status', 1)->exists()) {
            throw new \InvalidArgumentException('support_user_id must be an active user of this organisation (the person onboarding tickets are assigned to).');
        }
        $out = ['created' => [], 'existing' => [], 'invalid' => []];
        foreach (LeadAutomationPresets::all($options) as $d) {
            if (DB::table('gtm_workflows')->where('sub_institute_id', $tenant)->where('name', $d['name'])->exists()) {
                $out['existing'][] = $d['name'];

                continue;
            }
            $errors = WorkflowCatalog::validate($tenant, $d['trigger_event'], $d['conditions'], $d['actions']);
            if ($errors !== []) {
                $out['invalid'][$d['name']] = $errors;

                continue;
            }
            $id = DB::table('gtm_workflows')->insertGetId([
                'sub_institute_id' => $tenant, 'name' => $d['name'], 'description' => $d['description'], 'trigger_event' => $d['trigger_event'],
                'conditions' => json_encode($d['conditions'], JSON_UNESCAPED_UNICODE), 'actions' => json_encode($d['actions'], JSON_UNESCAPED_UNICODE),
                'status' => $activate ? 'active' : 'draft', 'activated_at' => $activate ? now() : null, 'created_by' => $adminUserId, 'updated_by' => $adminUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            GtmAudit::record('gtm.workflow.created', $tenant, 'gtm_workflows', $id, $adminUserId, ['name' => $d['name'], 'trigger' => $d['trigger_event'], 'via' => 'lead-automation-install']);
            $out['created'][] = $d['name'];
        }

        return $out;
    }
}
