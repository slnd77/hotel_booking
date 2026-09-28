<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('hold_release_at')->nullable()->after('status');
            $table->boolean('hold_auto_release')->default(false)->after('hold_release_at');
            $table->json('hold_reminders')->nullable()->after('hold_auto_release');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['hold_release_at', 'hold_auto_release', 'hold_reminders']);
        });
    }
};
