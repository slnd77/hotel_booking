<?php

use App\Models\ChargeCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shared charge codes the ledger mirror posts with (property_id = null).
     */
    public function up(): void
    {
        $now = now();

        foreach (ChargeCode::SYSTEM_CODES as $code => [$name, $category]) {
            DB::table('charge_codes')->updateOrInsert(
                ['property_id' => null, 'code' => $code],
                ['name' => $name, 'category' => $category, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('charge_codes')->whereNull('property_id')->whereIn('code', array_keys(ChargeCode::SYSTEM_CODES))->delete();
    }
};
