<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room blocks used a fixed `reason` string ('booking' / 'maintenance'); they
 * now point at the configurable block_reasons table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_blocks', function (Blueprint $table) {
            $table->foreignId('block_reason_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });

        $reasonIds = DB::table('block_reasons')->pluck('id', 'name');

        foreach (['booking' => 'Booking', 'maintenance' => 'Maintenance'] as $oldValue => $name) {
            DB::table('room_blocks')->where('reason', $oldValue)->update(['block_reason_id' => $reasonIds[$name]]);
        }

        DB::table('room_blocks')->whereNull('block_reason_id')->update(['block_reason_id' => $reasonIds['Booking']]);

        Schema::table('room_blocks', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }

    public function down(): void
    {
        Schema::table('room_blocks', function (Blueprint $table) {
            $table->string('reason')->default('booking')->after('user_id');
        });

        $maintenanceId = DB::table('block_reasons')->where('name', 'Maintenance')->value('id');
        DB::table('room_blocks')->where('block_reason_id', $maintenanceId)->update(['reason' => 'maintenance']);

        Schema::table('room_blocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('block_reason_id');
        });
    }
};
