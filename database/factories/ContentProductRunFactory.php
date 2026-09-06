<?php

namespace Database\Factories;

use App\Models\ContentProductRun;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentProductRunFactory extends Factory
{
    protected $model = ContentProductRun::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'trigger' => 'admin',
            'status' => ContentProductRun::STATUS_PENDING,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ];
    }
}
