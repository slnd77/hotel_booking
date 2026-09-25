<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_categories', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->foreignId('property_id')->after('id')->constrained('properties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_categories', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn(['property_id', 'name']);
        });
    }
};
