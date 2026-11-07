<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        DB::table('block_reasons')->insert([
            ['name' => 'Booking', 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Maintenance', 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('block_reasons');
    }
};
