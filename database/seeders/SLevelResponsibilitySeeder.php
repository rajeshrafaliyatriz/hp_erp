<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\libraries\SLevelResponsibility;

class SLevelResponsibilitySeeder extends Seeder
{
    public function run()
    {
        SLevelResponsibility::factory()->count(20)->create();

        SLevelResponsibility::create([
            'level' => '3',
            'guiding_phrase' => 'Works',
            'essence_level' => 'Able to complete work without close supervision.',
            'attribute_code' => 'AU',
            'attribute_name' => 'Autonomy',
            'attribute_type' => 'Core',
            'attribute_overall_description' => 'Operates with minimal guidance.',
            'attribute_guidance_notes' => 'Can self-manage most responsibilities.',
            'attribute_description' => 'Expected to manage own workload with regular reporting.',
        ]);
    }
}
