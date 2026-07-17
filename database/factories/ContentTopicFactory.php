<?php

namespace Database\Factories;

use App\Models\ContentPlan;
use App\Models\ContentTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ContentTopic> */
class ContentTopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => ContentPlan::factory(),
            'website_id' => function (array $attributes) {
                return ContentPlan::find($attributes['plan_id'])->website_id;
            },
            'title' => rtrim($this->faker->unique()->sentence(6), '.'),
            'target_keyword' => $this->faker->unique()->words(3, true),
            'secondary_keywords' => ['related one', 'related two'],
            'intent' => 'informational',
            'source' => 'llm',
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->addDay(),
            'position' => 0,
        ];
    }
}
