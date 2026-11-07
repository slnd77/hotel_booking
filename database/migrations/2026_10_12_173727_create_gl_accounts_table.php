<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each property's chart of accounts. System accounts (system_key) are created with the
     * chart and are what postings default to; staff can rename them and add their own.
     */
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 20);
            $table->string('system_key', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'code']);
            $table->unique(['property_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};
