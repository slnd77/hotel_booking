<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folios are the financial containers, owned by a sub-booking (one room), a
     * booking (master/group) or a company profile. Each has one or more billing
     * windows (sub-folios), optionally paid by someone else.
     */
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner'); // sub_booking | booking | company_profile
            $table->string('status')->default('open'); // open | locked | settled
            $table->string('currency', 3)->default('INR');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
        });

        Schema::create('sub_folios', function (Blueprint $table) {
            $table->id();
            // Deleting a folio removes its windows, but a window that has ledger entries blocks it (see transactions).
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('window_number')->default(1);
            $table->string('window_name');
            // Who settles this window when it isn't the folio owner (a guest or a company profile).
            $table->nullableMorphs('payer');
            $table->timestamps();

            $table->unique(['folio_id', 'window_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_folios');
        Schema::dropIfExists('folios');
    }
};
