<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's "Set your default list view" choices: table key => column key => shown.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('list_views')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('list_views');
        });
    }
};
