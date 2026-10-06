<?php

namespace App\Console\Commands;

use App\Models\tblmenumaster_g2gModel;
use App\Models\user\tblgroupwise_rights_g2gModel;
use App\Models\user\tbluserprofilemasterModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedG2gDefaultViewRights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'g2g:seed-default-view-rights {--force : Grant anyway, understanding this reverses revoked permissions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grants can_view=1 for every active profile x menu with no rights row. DESTRUCTIVE after go-live: it reverses revoked permissions. Requires --force.';

    /**
     * Execute the console command.
     *
     * ── WHY THIS NOW REFUSES BY DEFAULT ─────────────────────────────────────
     *
     * REVOCATION IN THIS SYSTEM IS ROW ABSENCE. storeGroupwiseRightsG2g deletes a
     * profile's entire rights set and re-inserts only the ticked boxes, so there
     * is not one can_view = 0 row in either database. "Never granted" and
     * "deliberately taken away" are the same state.
     *
     * This command grants can_view = 1 to every profile x menu pair that has no
     * row — which means running it SILENTLY RESTORES EVERY PERMISSION ANY
     * ADMINISTRATOR HAS EVER REMOVED, and reports it as a count of rows inserted.
     *
     * That was safe exactly once: on an empty rights table, before anyone had
     * curated anything. It is not safe now. The dry run below shows what would
     * be re-granted, and --force is required to proceed.
     */
    public function handle()
    {
        $profileIds = tbluserprofilemasterModel::where('status', 1)->pluck('id');
        $menuIds = tblmenumaster_g2gModel::where('status', 1)->pluck('id');

        $existing = tblgroupwise_rights_g2gModel::select('profile_id', 'menu_id')->get()
            ->map(fn ($r) => $r->profile_id.':'.$r->menu_id)
            ->flip();

        $rows = [];
        foreach ($profileIds as $profileId) {
            foreach ($menuIds as $menuId) {
                if (isset($existing[$profileId.':'.$menuId])) {
                    continue;
                }
                $rows[] = [
                    'menu_id' => $menuId,
                    'profile_id' => $profileId,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ];
            }
        }

        if ($rows === []) {
            $this->info('Nothing to grant — every active profile already has a row for every menu.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn(count($rows).' profile/menu pairs currently have NO rights row.');
            $this->line('');
            $this->line('Because revocation is stored as row absence, this command cannot tell');
            $this->line('"never granted" from "an administrator revoked it". Granting all of');
            $this->line('these would REVERSE every permission anyone has removed.');
            $this->line('');

            // Name a few, so the operator can judge rather than guess.
            foreach (array_slice($rows, 0, 5) as $row) {
                $menu = tblmenumaster_g2gModel::where('id', $row['menu_id'])->value('menu_name');
                $profile = tbluserprofilemasterModel::where('id', $row['profile_id'])->value('name');
                $this->line(sprintf('  profile %-28s would regain  %s', $profile ?? $row['profile_id'], $menu ?? $row['menu_id']));
            }

            if (count($rows) > 5) {
                $this->line('  … and '.(count($rows) - 5).' more.');
            }

            $this->line('');
            $this->error('Refusing without --force. Re-run with --force if that is genuinely intended.');

            return self::FAILURE;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('tblgroupwise_rights_g2g')->insert($chunk);
        }

        $this->info('Inserted '.count($rows).' default can_view=1 right(s).');

        return self::SUCCESS;
    }
}
