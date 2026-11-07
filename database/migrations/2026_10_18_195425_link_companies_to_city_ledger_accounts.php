<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest's company, a company folio owner and a booking billed to the company point at one
     * City Ledger account; the invoice keeps the account it was billed to and when it is due.
     * Existing guests and company profiles are linked to the property's account of the same
     * GSTIN or name.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('city_ledger_account_id')->nullable()->after('company')->constrained()->nullOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('city_ledger_account_id')->nullable()->after('bill_extras_to_guest_company')->constrained()->nullOnDelete();
        });

        Schema::table('company_profiles', function (Blueprint $table) {
            $table->foreignId('city_ledger_account_id')->nullable()->after('property_id')->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('city_ledger_account_id')->nullable()->after('corporate_id')->constrained()->nullOnDelete();
            $table->date('due_date')->nullable()->after('city_ledger_account_id');
        });

        $accounts = DB::table('city_ledger_accounts')->get(['id', 'property_id', 'name', 'tax_number'])->groupBy('property_id');
        $match = function (?int $propertyId, ?string $name, ?string $gstin) use ($accounts): ?int {
            $candidates = $accounts->get($propertyId, collect());
            $gstin = strtoupper(trim((string) $gstin));
            $name = mb_strtolower(trim((string) $name));

            return ($gstin !== '' ? $candidates->first(fn ($account): bool => strtoupper(trim((string) $account->tax_number)) === $gstin) : null)?->id
                ?? ($name !== '' ? $candidates->first(fn ($account): bool => mb_strtolower(trim($account->name)) === $name) : null)?->id;
        };

        DB::table('company_profiles')->orderBy('id')->each(function ($profile) use ($match): void {
            if ($id = $match($profile->property_id, $profile->name, $profile->tax_number)) {
                DB::table('company_profiles')->where('id', $profile->id)->update(['city_ledger_account_id' => $id]);
            }
        });

        DB::table('guests')->whereNotNull('company')->orderBy('id')->each(function ($guest) use ($match): void {
            $company = json_decode((string) $guest->company, true);

            if (is_array($company) && ($id = $match($guest->property_id, $company['name'] ?? null, $company['gstin'] ?? null))) {
                DB::table('guests')->where('id', $guest->id)->update(['city_ledger_account_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_ledger_account_id');
            $table->dropColumn('due_date');
        });

        foreach (['company_profiles', 'bookings', 'guests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('city_ledger_account_id');
            });
        }
    }
};
