<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolSetupSeeder extends Seeder
{
    public function run(): void
    {
        $clientId = DB::table('tblclient')->value('id'); // Get the first client's ID

        DB::table('school_setup')->insert([
            'SchoolName' => 'Triz High School',
            'ShortCode' => 'THS',
            'ContactPerson' => 'Rajesh rafaliya',
            'Mobile' => '9988776655',
            'Email' => 'contact@trizschool.com',
            'ReceiptHeader' => 'Triz School Receipt',
            'ReceiptAddress' => '123, Education Street, Pune',
            'FeeEmail' => 'fees@trizschool.com',
            'ReceiptContact' => '020-4000000',
            'SortOrder' => 1,
            'Logo' => 'scholar_clone.png',
            'client_id' => $clientId,
            'is_lms' => 'Y',
            'cheque_return_charges' => 250,
            'syear' => '2025',
            'expire_date' => now()->addYear()->toDateString(),
            'given_space_mb' => 2048,
            'institute_type' => 'Common General Services',
            'created_at' => now(),
        ]);
    }
}
