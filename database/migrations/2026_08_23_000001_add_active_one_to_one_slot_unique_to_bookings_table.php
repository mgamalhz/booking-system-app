<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('active_one_to_one_slot_id')->nullable()->after('slot_id');
        });

        DB::table('bookings')
            ->where('type', 'one-on-one')
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNull('deleted_at')
            ->update(['active_one_to_one_slot_id' => DB::raw('slot_id')]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('active_one_to_one_slot_id', 'bookings_active_one_to_one_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_active_one_to_one_slot_unique');
            $table->dropColumn('active_one_to_one_slot_id');
        });
    }
};
