<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports e-mailed from the Reports page: each scheduled daily send (one per report and
     * property day, so a send is never repeated) and each "E-mail now", with its outcome.
     */
    public function up(): void
    {
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('report', 60);
            $table->date('report_date');
            $table->date('report_end_date')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            // The property day a scheduled send belongs to; null for "E-mail now".
            $table->date('scheduled_for')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recipients');
            $table->string('status', 20)->default('queued'); // queued | sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'report', 'scheduled_for'], 'report_deliveries_schedule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
    }
};
