<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('room_category_id')->after('id')->constrained('room_categories')->cascadeOnDelete();
            $table->string('name')->after('room_category_id');
            $table->unsignedInteger('display_order')->default(0)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['room_category_id']);
            $table->dropColumn(['room_category_id', 'name', 'display_order']);
        });
    }
};
