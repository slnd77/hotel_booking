<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The parent booking's primary booker. Existing bookings take their first linked guest.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable()->after('property_id')->constrained('guests')->nullOnDelete();
        });

        // The first guest attached to each booking (lowest pivot id) becomes its primary booker.
        $firstLinkIds = DB::table('booking_guests')->selectRaw('MIN(id) as id')->groupBy('booking_id');

        DB::table('booking_guests')
            ->whereIn('id', $firstLinkIds)
            ->orderBy('id')
            ->chunk(500, function ($links): void {
                foreach ($links as $link) {
                    DB::table('bookings')->where('id', $link->booking_id)->update(['guest_id' => $link->guest_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_id');
        });
    }
};
