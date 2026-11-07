<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agents are soft deleted: a removed contact leaves the Agents list and the booking form's
     * picker, but bookings made through it keep their agent.
     */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
