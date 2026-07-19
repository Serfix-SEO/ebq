<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ContentPlan> */
class ContentPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'website_id' => Website::factory()->for(User::factory()),
            'status' => 'active',
            // A plan in a test implies the website is billing-covered (real prod
            // coverage is set explicitly by the trial/checkout flow).
            'billing_covered_at' => now(),
            'articles_per_week' => 3,
            'article_length' => 2000,
            'auto_publish' => false,
            'review_hours' => 24,
            'business_description' => $this->faker->sentence(12),
            'offerings' => ['sell' => ['Widgets'], 'dont_sell' => ['Repairs']],
            'language' => 'en',
            'country' => 'US',
        ];
    }
}
