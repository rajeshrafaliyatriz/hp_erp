<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TblClientSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tblclient')->insert([
            'client_name' => 'Triz Technologies',
            'short_code' => 'TRZ',
            'logo' => 'triz-logo.png',
            'address' => '123 Tech Park, Mumbai',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'country' => 'India',
            'email' => 'info@triz.co.in',
            'contact_person' => 'John Doe',
            'contact_person_mobile' => '9876543210',
            'contact_persoon_email' => 'john.doe@triz.co.in',
            'trustee_name' => 'Mr. Smith',
            'trustee_emai' => 'smith@triztrust.org',
            'trustee_mobile' => '9123456789',
            'number_of_schools' => 3,
            'db_host' => 'localhost',
            'db_user' => 'triz_user',
            'db_password' => 'secret',
            'db_solution' => 'solution_db',
            'db_cms' => 'cms_db',
            'db_hrms' => 'hrms_db',
            'db_library' => 'library_db',
            'db_lms' => 'lms_db',
            'multischool' => true,
            'total_student' => 1200,
            'total_staff' => 85,
            'hrms_folder' => 'hrms',
            'old_url' => 'http://old.triz.co.in',
            'created_at' => now(),
        ]);
    }
}
