<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Balanced journal entries: their lines' debits equal their credits. Append-only; a
     * correction is a new entry.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->timestamp('posted_at');
            $table->string('description', 500)->nullable();
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['property_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
