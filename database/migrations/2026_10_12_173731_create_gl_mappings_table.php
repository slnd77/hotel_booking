<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a charge code or payment mode posts in a property's books, when not the default.
     */
    public function up(): void
    {
        Schema::create('gl_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('mapping_type', 20);
            $table->string('key', 60);
            $table->foreignId('gl_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'mapping_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_mappings');
    }
};
