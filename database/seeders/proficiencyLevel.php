<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use DB;

class proficiencyLevel extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       $data = [
            [
                'skill_id' => null,
                'proficiency_level' => 'Explorer',
                'description' => 'Description for proficiency level',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Explorer',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'skill_id' => null,
                'proficiency_level' => 'Contributor',
                'description' => 'Description for proficiency Contributor',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Contributor',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'skill_id' => null,
                'proficiency_level' => 'Practitioner',
                'description' => 'Description for proficiency Practitioner',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Practitioner',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'skill_id' => null,
                'proficiency_level' => 'Expert',
                'description' => 'Description for proficiency Expert',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Expert',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'skill_id' => null,
                'proficiency_level' => 'Coach',
                'description' => 'Description for proficiency Coach',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Coach',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'skill_id' => null,
                'proficiency_level' => 'Change Leader',
                'description' => 'Description for proficiency Change Leader',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Change Leader',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'skill_id' => null,
                'proficiency_level' => 'Strategic',
                'description' => 'Description for proficiency Strategic',
                'proficiency_type' => 'Autonomy',
                'type_description' => 'Type description for Strategic',
                'sub_institute_id' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ];

        DB::table('s_proficiency_levels')->insert($data);
    }
}
