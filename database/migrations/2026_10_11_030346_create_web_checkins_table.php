<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web check-in: the guest's pre-arrival form for a booking, opened from a link carrying a
     * random token. Holds what the guest submitted, their ID document and signature (files on
     * the private disk).
     */
    public function up(): void
    {
        Schema::create('web_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status')->default('pending'); // pending | completed
            $table->json('details')->nullable();
            $table->string('id_document_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_checkins');
    }
};
