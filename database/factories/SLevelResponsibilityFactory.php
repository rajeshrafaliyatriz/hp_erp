<?php

namespace Database\Factories;

use App\Models\libraries\SLevelResponsibility;
use Illuminate\Database\Eloquent\Factories\Factory;

class SLevelResponsibilityFactory extends Factory
{
    protected $model = SLevelResponsibility::class;

    public function definition()
    {
        return [
            'level' => $this->faker->randomElement(['1', '2', '3', '4', '5', '6', '7']),
            'guiding_phrase' => $this->faker->words(1, true),
            'essence_level' => $this->faker->paragraph,
            'attribute_code' => $this->faker->lexify('A??'),
            'attribute_name' => $this->faker->randomElement(['Autonomy', 'Influence', 'Complexity', 'Knowledge', 'Business Skills']),
            'attribute_type' => $this->faker->randomElement(['Core', 'Technical']),
            'attribute_overall_description' => $this->faker->sentence,
            'attribute_guidance_notes' => $this->faker->paragraph,
            'attribute_description' => $this->faker->text,
        ];
    }
}
