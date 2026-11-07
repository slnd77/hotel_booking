<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedBigInteger('currency_id')->change();
            $table->unsignedBigInteger('language')->change();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('language')->references('id')->on('languages')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['language']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->integer('currency_id')->change();
            $table->integer('language')->change();
        });
    }
};
