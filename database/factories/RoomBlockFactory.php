<?php

namespace Database\Factories;

use App\Models\BlockReason;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomBlock>
 */
class RoomBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = now()->addDays(fake()->numberBetween(0, 10))->startOfDay();

        return [
            'property_id' => Property::factory(),
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
            'block_reason_id' => fn (): int => BlockReason::query()->value('id') ?? BlockReason::factory()->create()->id,
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->copy()->addDays(fake()->numberBetween(1, 5))->toDateString(),
            'name' => fake()->optional()->name(),
            'remarks' => fake()->sentence(),
        ];
    }

    /**
     * @return $this
     */
    public function forRoom(Room $room): static
    {
        return $this->state(fn (): array => [
            'room_id' => $room->id,
            'property_id' => $room->roomCategory->property_id,
        ]);
    }

    /**
     * @return $this
     */
    public function between(string $startDate, string $endDate): static
    {
        return $this->state(fn (): array => ['start_date' => $startDate, 'end_date' => $endDate]);
    }
}
