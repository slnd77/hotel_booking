<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kitchen order tickets: what a bill sent to the kitchen each time. A ticket holds only the
     * change since the bill's last one (items added, or taken off as cancellations).
     */
    public function up(): void
    {
        Schema::create('outlet_kots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_bill_id')->constrained()->cascadeOnDelete();
            $table->string('kot_number');
            $table->date('business_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'kot_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_kots');
    }
};
