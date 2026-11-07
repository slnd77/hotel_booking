<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * City ledger (direct bill) accounts: companies and agencies the property bills on credit,
     * up to a credit limit, payable within their terms.
     */
    public function up(): void
    {
        Schema::create('city_ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->string('account_type', 20)->default('company');
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tax_number', 40)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_ledger_accounts');
    }
};
