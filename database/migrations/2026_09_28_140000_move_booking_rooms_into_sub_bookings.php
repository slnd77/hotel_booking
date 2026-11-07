<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-bookings become the only record of a booking's rooms: they take over the
     * nightly rate, and the legacy booking_rooms table (mirrored since 120003) goes.
     * Foreign keys follow what booking_rooms did: deleting a room category removes
     * its stays, deleting a room leaves its stays unassigned.
     */
    public function up(): void
    {
        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->decimal('rate_per_night', 12, 2)->default(0)->after('infants');
        });

        // Safety net: any legacy room the mirror hasn't copied yet.
        $now = now();
        $missing = DB::table('booking_rooms')
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->leftJoin('sub_bookings', 'sub_bookings.booking_room_id', '=', 'booking_rooms.id')
            ->leftJoin('inventory_units', function ($join): void {
                $join->on('inventory_units.room_id', '=', 'booking_rooms.room_id')->where('inventory_units.unit_type', 'room');
            })
            ->whereNull('sub_bookings.id')
            ->get(['booking_rooms.*', 'bookings.check_in', 'bookings.check_out', 'inventory_units.id as unit_id']);

        foreach ($missing as $row) {
            DB::table('sub_bookings')->insert([
                'booking_id' => $row->booking_id,
                'inventory_unit_id' => $row->unit_id,
                'room_category_id' => $row->room_category_id,
                'booking_room_id' => $row->id,
                'check_in_date' => substr((string) $row->check_in, 0, 10),
                'check_out_date' => substr((string) $row->check_out, 0, 10),
                'status' => 'booked',
                'meal_plan' => $row->meal_plan,
                'adults' => $row->adults,
                'children' => $row->children,
                'infants' => $row->infants,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('booking_rooms')->orderBy('id')->chunk(500, function ($rooms): void {
            foreach ($rooms as $room) {
                DB::table('sub_bookings')->where('booking_room_id', $room->id)->update(['rate_per_night' => $room->rate_per_night]);
            }
        });

        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dropForeign(['booking_room_id']);
            $table->dropUnique(['booking_room_id']);
            $table->dropColumn('booking_room_id');

            $table->dropForeign(['inventory_unit_id']);
            $table->foreign('inventory_unit_id')->references('id')->on('inventory_units')->nullOnDelete();
            $table->dropForeign(['room_category_id']);
            $table->foreign('room_category_id')->references('id')->on('room_categories')->cascadeOnDelete();
        });

        Schema::dropIfExists('booking_rooms');
    }

    /**
     * Rebuilds booking_rooms from sub-bookings.
     */
    public function down(): void
    {
        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained('room_categories')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('meal_plan')->nullable();
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->unsignedInteger('infants')->default(0);
            $table->decimal('rate_per_night', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->foreignId('booking_room_id')->nullable()->unique()->constrained('booking_rooms')->cascadeOnDelete();
        });

        DB::table('sub_bookings')
            ->leftJoin('inventory_units', 'inventory_units.id', '=', 'sub_bookings.inventory_unit_id')
            ->orderBy('sub_bookings.id')
            ->get(['sub_bookings.*', 'inventory_units.room_id'])
            ->each(function ($stay): void {
                $bookingRoomId = DB::table('booking_rooms')->insertGetId([
                    'booking_id' => $stay->booking_id,
                    'room_category_id' => $stay->room_category_id,
                    'room_id' => $stay->room_id,
                    'meal_plan' => $stay->meal_plan,
                    'adults' => $stay->adults,
                    'children' => $stay->children,
                    'infants' => $stay->infants,
                    'rate_per_night' => $stay->rate_per_night,
                    'created_at' => $stay->created_at,
                    'updated_at' => $stay->updated_at,
                ]);

                DB::table('sub_bookings')->where('id', $stay->id)->update(['booking_room_id' => $bookingRoomId]);
            });

        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dropColumn('rate_per_night');
        });
    }
};
