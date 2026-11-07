<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Booking has no factory, so pass the booking with forBooking().
 *
 * @extends Factory<BookingAttachment>
 */
class BookingAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fileName = fake()->uuid().'.pdf';

        return [
            'user_id' => null,
            'document_type' => fake()->randomElement(['Aadhaar Card', 'Passport', 'Driving License', 'Pan Card']),
            'document_number' => fake()->bothify('??######'),
            'expiry_date' => fake()->optional()->dateTimeBetween('+1 month', '+5 years'),
            'path' => "booking-attachments/{$fileName}",
            'original_name' => $fileName,
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(10_000, 2_000_000),
        ];
    }

    /**
     * @return $this
     */
    public function forBooking(Booking $booking): static
    {
        return $this->state(fn (): array => ['booking_id' => $booking->id]);
    }
}
