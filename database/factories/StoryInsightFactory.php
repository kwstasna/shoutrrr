<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PostTarget;
use App\Models\StoryInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryInsight>
 */
class StoryInsightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_target_id' => PostTarget::factory()->story(),
            'captured_at' => now(),
            'reach' => fake()->numberBetween(0, 5000),
            'impressions' => null,
            'replies' => fake()->numberBetween(0, 100),
            'shares' => fake()->numberBetween(0, 50),
            'total_interactions' => fake()->numberBetween(0, 200),
            'profile_visits' => fake()->numberBetween(0, 80),
            'follows' => fake()->numberBetween(0, 20),
            'navigation' => fake()->numberBetween(0, 300),
            'views' => fake()->numberBetween(0, 5000),
            'raw' => [],
        ];
    }
}
