<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use DB;
class TblGroupwiseRights extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userProfileId = DB::table('tbluserprofilemaster')->value('id');
        $clientId = DB::table('tblclient')->value('id');
        $menuId = DB::table('tblmenumaster')->where('status',1)->get()->toArray();
        
    foreach ($menuId as $menu) {
        // Example seed data for tblgroupwiserights table
        \DB::table('tblgroupwise_rights')->insert([
            [
            'menu_id' =>$menu->id,
            'profile_id' => $userProfileId,
            'can_view' => 1,
            'can_add' => 1,
            'can_edit' => 1,
            'can_delete' => 1,
            'dashboard_right' => 0,
            'sub_institute_id' => 1,
            'sort_order' => 1,
            'deleted_at'=>null,
            'created_at' => now(),
            'updated_at' => null,
            ],
            [
                'menu_id' =>$menu->id,
                'profile_id' => 3,
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
                'dashboard_right' => 0,
                'sub_institute_id' => 1,
                'sort_order' => 1,
                'deleted_at'=>null,
                'created_at' => now(),
                'updated_at' => null,
            ],
        ]);
    }
        
    }
}
