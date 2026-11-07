<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per closed business date of a property (its local date; the times are UTC).
     */
    public function up(): void
    {
        Schema::create('night_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audits');
    }
};
