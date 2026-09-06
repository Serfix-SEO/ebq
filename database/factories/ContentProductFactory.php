<?php

namespace Database\Factories;

use App\Models\ContentProduct;
use App\Models\Website;
use App\Services\Content\Catalog\ProductExtractor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentProductFactory extends Factory
{
    protected $model = ContentProduct::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->words(3, true));
        $url = 'https://shop.example.com/products/'.$this->faker->unique()->slug();

        return [
            'website_id' => Website::factory(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'name' => $name,
            'description' => $this->faker->sentence(12),
            'availability' => ContentProduct::AVAILABILITY_IN_STOCK,
            'category' => 'general',
            'terms' => ProductExtractor::terms($name, 'general', null),
            'source' => ContentProduct::SOURCE_JSONLD,
            'status' => ContentProduct::STATUS_ACTIVE,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
