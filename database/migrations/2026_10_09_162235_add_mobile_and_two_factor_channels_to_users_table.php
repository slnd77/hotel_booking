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
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile_country_code', 10)->nullable()->after('email');
            $table->string('mobile', 20)->nullable()->after('mobile_country_code');
            // Two-factor codes sent by e-mail, SMS or WhatsApp that the user turned on (TOTP and
            // passkeys keep their own columns / table).
            $table->json('two_factor_channels')->nullable()->after('two_factor_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mobile_country_code', 'mobile', 'two_factor_channels']);
        });
    }
};
