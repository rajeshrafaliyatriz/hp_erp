<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Provisions or verifies baseline access rights for Platform Services and AI & Intelligence
 * across all tenant administrator profiles.
 *
 * Usage:
 *   php artisan rbac:grant-capabilities                    # Dry-run on default (dev) DB
 *   php artisan rbac:grant-capabilities --apply            # Apply on default (dev) DB
 *   php artisan rbac:grant-capabilities --apply --database=live  # Apply on live DB (db3)
 */
class RbacGrantCapabilitiesCommand extends Command
{
    protected $signature = 'rbac:grant-capabilities
        {--apply : Commit the inserts to the database (dry-run by default)}
        {--database= : Specific database connection (e.g. mysql, db3, live)}
        {--tenant= : Restrict to a specific tenant ID}';

    protected $description = 'Provisions baseline Platform Services and AI capability rights to tenant administrator profiles.';

    private const CANONICAL_LINKS = [
        '/platform-services/workflow?module=organization',
        '/platform-services/workflow?module=hrms',
        '/platform-services/workflow?module=talent',
        '/platform-services/workflow?module=lms',
        '/platform-services/workflow?module=competency',
        '/platform-services/workflow?module=task',
        '/platform-services/workflow?module=crm',
        '/platform-services/event-bus',
        '/settings?s=audit',
        '/platform-services',
        '/platform-services/whats-coming',
        '/documents',
        '/ai/providers',
        '/ai/models',
        '/ai/prompts',
        '/ai/policies',
        '/ai/conversational-ai',
        '/ai/knowledge-rag',
        '/ai/recommendations',
        '/ai/knowledge-graph',
        '/ai/evaluation',
        '/ai/usage-cost',
        '/ai/audit',
    ];

    public function handle(): int
    {
        $database = $this->option('database');
        if ($database !== null && $database !== '') {
            if (config("database.connections.{$database}") === null) {
                $this->error("Connection '{$database}' is not configured.");
                return self::INVALID;
            }
            DB::setDefaultConnection($database);
        }

        $currentConn = DB::getDefaultConnection();
        $this->info("Target database connection: {$currentConn}");

        $apply = (bool) $this->option('apply');
        $targetTenant = $this->option('tenant');

        $menuMap = [];
        foreach (self::CANONICAL_LINKS as $link) {
            $id = DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id');
            if ($id) {
                $menuMap[$link] = $id;
            } else {
                $this->warn("Menu link '{$link}' missing from tblmenumaster_g2g!");
            }
        }

        $query = DB::table('tbluserprofilemaster')
            ->where(function ($q) {
                $q->where('name', 'Admin')
                  ->orWhere('name', 'Administrator')
                  ->orWhere('role_key', 'administrator');
            });

        if ($targetTenant !== null && $targetTenant !== '') {
            $query->where('sub_institute_id', $targetTenant);
        }

        $adminProfiles = $query->orderBy('sub_institute_id')->get(['id', 'name', 'sub_institute_id']);
        $this->info("Found " . $adminProfiles->count() . " administrator profiles.");

        $totalInserted = 0;

        foreach ($adminProfiles as $profile) {
            $existing = DB::table('tblgroupwise_rights_g2g')
                ->where('profile_id', $profile->id)
                ->whereIn('menu_id', array_values($menuMap))
                ->pluck('menu_id')
                ->toArray();

            $toInsert = [];
            foreach ($menuMap as $link => $menuId) {
                if (!in_array($menuId, $existing)) {
                    $toInsert[] = [
                        'menu_id' => $menuId,
                        'profile_id' => $profile->id,
                        'sub_institute_id' => $profile->sub_institute_id,
                        'can_view' => 1,
                        'can_add' => ($link === '/platform-services/workflow?module=crm' ? 1 : 0),
                        'can_edit' => ($link === '/platform-services/workflow?module=crm' ? 1 : 0),
                        'can_delete' => ($link === '/platform-services/workflow?module=crm' ? 1 : 0),
                        'dashboard_right' => 0,
                        'is_mobile' => 0,
                        'created_at' => now(),
                    ];
                }
            }

            $missingCount = count($toInsert);
            if ($missingCount > 0) {
                $this->line("Tenant {$profile->sub_institute_id} (Profile {$profile->id}): missing {$missingCount} rights.");
                if ($apply) {
                    DB::table('tblgroupwise_rights_g2g')->insert($toInsert);
                    $totalInserted += $missingCount;
                }
            } else {
                $this->line("Tenant {$profile->sub_institute_id} (Profile {$profile->id}): 23/23 OK.");
            }
        }

        if ($apply) {
            $this->info("Successfully provisioned {$totalInserted} rows.");
        } else {
            $this->comment("Dry-run complete. Pass --apply to write changes.");
        }

        return self::SUCCESS;
    }
}
