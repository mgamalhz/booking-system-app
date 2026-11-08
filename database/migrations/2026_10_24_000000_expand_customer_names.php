<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Refuse a table-copy fallback on MySQL; fail and retry on long metadata locks.
            $timeout = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
            DB::statement('SET SESSION lock_wait_timeout = 5');
            try {
                DB::statement('ALTER TABLE customers ADD COLUMN given_name VARCHAR(255) NULL, ADD COLUMN family_name VARCHAR(255) NULL, ALGORITHM=INSTANT');
            } finally {
                DB::statement('SET SESSION lock_wait_timeout = '.$timeout);
            }

            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['given_name', 'family_name']);
        });
    }
};
