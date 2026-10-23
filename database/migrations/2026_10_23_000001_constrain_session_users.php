<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Despite the column name, this application's web guard authenticates customers.
        if (config('auth.providers.users.model') !== Customer::class) {
            throw new RuntimeException('Session identity constraint requires the Customer authentication provider.');
        }

        // Retain anonymous session payloads, but remove invalid identity references.
        DB::table('sessions')->whereNotNull('user_id')
            ->whereNotIn('user_id', DB::table('customers')->select('id'))
            ->update(['user_id' => null]);

        Schema::table('sessions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
