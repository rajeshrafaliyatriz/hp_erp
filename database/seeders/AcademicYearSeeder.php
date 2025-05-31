<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {
        // You can set your sub_institute_id here or fetch from DB
        $subInstituteId = DB::table('school_setup')->value('id');

        DB::table('academic_year')->insert([
            'syear' => date('Y'),
            'sub_institute_id' => $subInstituteId,
            'title' => 'Academic Year '.date('Y').'-'.(date('Y')+1),
            'short_name' => 'AY '.date('y').'-'.(date('y')+1),
            'sort_order' => 1,
            'start_date' => date('Y').'-04-01',
            'end_date' =>(date('y')+1).'-03-31',
            'post_start_date' =>date('y').'-04-01',
            'post_end_date' => (date('y')+1).'-03-31',
            'created_at' => now(),
        ]);
    }
}
