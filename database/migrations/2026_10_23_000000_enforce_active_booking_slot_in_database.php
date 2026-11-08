<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not silently cancel customer reservations to resolve real conflicts.
        $conflict = DB::table('bookings')->select('slot_id')
            ->where('type', 'one-on-one')->whereIn('status', ['pending', 'confirmed'])
            ->whereNull('deleted_at')->groupBy('slot_id')->havingRaw('COUNT(*) > 1')->first();

        if ($conflict !== null) {
            throw new RuntimeException("Resolve conflicting active one-on-one bookings for slot {$conflict->slot_id} before migrating.");
        }

        // A separate column permits an additive deployment with old application workers.
        // Existing rows are automatically backfilled from their authoritative fields.
        if (! Schema::hasColumn('bookings', 'enforced_one_to_one_slot_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unsignedBigInteger('enforced_one_to_one_slot_id')->nullable()->virtualAs(
                    "CASE WHEN type = 'one-on-one' AND status IN ('pending', 'confirmed') AND deleted_at IS NULL THEN slot_id ELSE NULL END"
                );
            });
        }

        // MySQL DDL commits implicitly: permit retry after a partially applied migration.
        if (! Schema::hasIndex('bookings', 'bookings_enforced_one_to_one_slot_unique')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unique('enforced_one_to_one_slot_id', 'bookings_enforced_one_to_one_slot_unique');
            });
        }

        if (Schema::hasIndex('bookings', 'bookings_active_one_to_one_slot_unique')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique('bookings_active_one_to_one_slot_unique');
            });
        }
    }

    public function down(): void
    {
        // Normalize stale legacy guards before restoring the previous constraint.
        DB::table('bookings')->update(['active_one_to_one_slot_id' => null]);
        DB::table('bookings')->where('type', 'one-on-one')
            ->whereIn('status', ['pending', 'confirmed'])->whereNull('deleted_at')
            ->update(['active_one_to_one_slot_id' => DB::raw('slot_id')]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('active_one_to_one_slot_id', 'bookings_active_one_to_one_slot_unique');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_enforced_one_to_one_slot_unique');
            $table->dropColumn('enforced_one_to_one_slot_id');
        });
    }
};
