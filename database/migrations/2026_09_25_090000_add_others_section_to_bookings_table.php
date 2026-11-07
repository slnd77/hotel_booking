<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('special_instructions')->nullable()->after('no_post');
            $table->text('internal_notes')->nullable()->after('special_instructions');
            $table->json('copy_to')->nullable()->after('internal_notes');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['special_instructions', 'internal_notes', 'copy_to']);
        });
    }
};
