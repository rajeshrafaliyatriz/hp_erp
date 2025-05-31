<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TblUserProfileMasterSeeder extends Seeder
{
    public function run(): void
    {
        // Get valid foreign key IDs
        $clientId = DB::table('tblclient')->value('id');
        $subInstituteId = DB::table('school_setup')->value('id');

        // Insert sample data
        DB::table('tbluserprofilemaster')->insert([
            [
                'parent_id' => null,
                'name' => 'Admin',
                'description' => 'Administrator with full permissions',
                'sort_order' => 1,
                'status' => 1,
                'sub_institute_id' => $subInstituteId,
                'client_id' => $clientId,
                'created_at' => now(),
            ],
            [
                'parent_id' => null,
                'name' => 'HR',
                'description' => 'Profile for staff',
                'sort_order' => 2,
                'status' => 1,
                'sub_institute_id' => $subInstituteId,
                'client_id' => $clientId,
                'created_at' => now(),
            ],
            [
                'parent_id' => null,
                'name' => 'Employee',
                'description' => 'Profile for employees',
                'sort_order' => 3,
                'status' => 1,
                'sub_institute_id' => $subInstituteId,
                'client_id' => $clientId,
                'created_at' => now(),
            ],
        ]);
    }
}
