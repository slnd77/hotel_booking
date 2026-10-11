<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A kitchen ticket's lines. A negative quantity cancels that many of an item already sent.
     */
    public function up(): void
    {
        Schema::create('outlet_kot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_kot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_kot_items');
    }
};
