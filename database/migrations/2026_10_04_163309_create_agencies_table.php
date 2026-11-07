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
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_rate_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('agent');
            $table->string('name');
            $table->string('code');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'name']);
            $table->unique(['property_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
