<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('room_bill_to')->default('to_guest')->after('outstanding');
            $table->string('extras_bill_to')->default('to_guest')->after('room_bill_to');
            $table->boolean('bill_room_to_guest_company')->default(false)->after('extras_bill_to');
            $table->boolean('bill_extras_to_guest_company')->default(false)->after('bill_room_to_guest_company');
            $table->boolean('no_post')->default(false)->after('bill_extras_to_guest_company');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['room_bill_to', 'extras_bill_to', 'bill_room_to_guest_company', 'bill_extras_to_guest_company', 'no_post']);
        });
    }
};
