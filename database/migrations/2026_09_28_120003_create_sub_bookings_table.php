<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Child reservations: one per occupied unit, each with its own dates and stay
     * status. Existing booking_rooms are copied across (linked by booking_room_id)
     * so both layers can be read side by side until the app moves over.
     */
    public function up(): void
    {
        Schema::create('sub_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            // Null while no unit is assigned yet; a unit with stays on it can't be deleted.
            $table->foreignId('inventory_unit_id')->nullable()->constrained()->restrictOnDelete();
            // What was sold; lets an unassigned stay still count against its category.
            $table->foreignId('room_category_id')->constrained('room_categories')->restrictOnDelete();
            // Transition link to the legacy booking_rooms row.
            $table->foreignId('booking_room_id')->nullable()->unique()->constrained('booking_rooms')->nullOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->string('status')->default('booked'); // hold | booked | checked_in | checked_out | cancelled | no_show
            $table->string('meal_plan')->nullable();
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('infants')->default(0);
            $table->timestamps();

            $table->index(['inventory_unit_id', 'check_in_date', 'check_out_date'], 'sub_bookings_unit_dates_index');
            $table->index(['booking_id', 'status']);
        });

        $statusMap = [
            'on_hold' => 'hold',
            'enquiry' => 'hold',
            'confirmed' => 'booked',
            'pending' => 'booked',
            'checked_in' => 'checked_in',
            'checked_out' => 'checked_out',
            'cancelled' => 'cancelled',
            'expired_quote' => 'cancelled',
            'no_show' => 'no_show',
        ];
        $now = now();

        DB::table('booking_rooms')
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->leftJoin('inventory_units', function ($join): void {
                $join->on('inventory_units.room_id', '=', 'booking_rooms.room_id')->where('inventory_units.unit_type', 'room');
            })
            ->orderBy('booking_rooms.id')
            ->select([
                'booking_rooms.*',
                'bookings.check_in',
                'bookings.check_out',
                'bookings.status as booking_status',
                'inventory_units.id as inventory_unit_id',
            ])
            ->chunk(500, function ($rows) use ($statusMap, $now): void {
                DB::table('sub_bookings')->insert($rows->map(fn ($row): array => [
                    'booking_id' => $row->booking_id,
                    'inventory_unit_id' => $row->inventory_unit_id,
                    'room_category_id' => $row->room_category_id,
                    'booking_room_id' => $row->id,
                    'check_in_date' => substr((string) $row->check_in, 0, 10),
                    'check_out_date' => substr((string) $row->check_out, 0, 10),
                    'status' => $statusMap[$row->booking_status] ?? 'booked',
                    'meal_plan' => $row->meal_plan,
                    'adults' => $row->adults,
                    'children' => $row->children,
                    'infants' => $row->infants,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_bookings');
    }
};
