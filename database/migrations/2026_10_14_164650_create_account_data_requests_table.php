<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manage Account → Data & privacy: exports of all the account's data, and the account's
     * deletion (scheduled after a grace period, cancellable until then).
     */
    public function up(): void
    {
        Schema::create('account_data_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // export | deletion
            $table->string('status');
            $table->unsignedBigInteger('user_id')->nullable(); // who asked
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_data_requests');
    }
};
