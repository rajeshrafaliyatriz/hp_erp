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
    protected $signature = 'g2g:seed-default-view-rights';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grants can_view=1 in tblgroupwise_rights_g2g for every active profile x menu row that has no rights row yet, so the new sidebar is not empty until an admin curates real rights.';

    /**
     * Execute the console command.
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

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('tblgroupwise_rights_g2g')->insert($chunk);
        }

        $this->info('Inserted '.count($rows).' default can_view=1 right(s).');

        return self::SUCCESS;
    }
}
