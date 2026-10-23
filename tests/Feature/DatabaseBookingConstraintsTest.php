<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rawConstraintBooking(array $overrides = []): array
{
    $booking = Booking::factory()->create(['type' => 'group']);
    $row = DB::table('bookings')->where('id', $booking->id)->first();
    $values = (array) $row;
    unset($values['id'], $values['enforced_one_to_one_slot_id']);

    return array_replace($values, ['type' => 'one-on-one'], $overrides);
}

test('raw inserts cannot bypass active slot uniqueness with an empty legacy guard', function () {
    $row = rawConstraintBooking();
    DB::table('bookings')->insert($row);
    expect(fn () => DB::table('bookings')->insert($row))->toThrow(QueryException::class);
});

test('updates into an occupied active slot are rejected', function () {
    $row = rawConstraintBooking();
    DB::table('bookings')->insert($row);
    $id = DB::table('bookings')->insertGetId(array_replace($row, ['status' => 'canceled']));
    expect(fn () => DB::table('bookings')->where('id', $id)->update(['status' => 'confirmed']))
        ->toThrow(QueryException::class);
});

test('inactive bookings release their slot even when the legacy guard is stale', function (array $change) {
    $row = rawConstraintBooking();
    $row['active_one_to_one_slot_id'] = $row['slot_id'];
    $id = DB::table('bookings')->insertGetId($row);
    DB::table('bookings')->where('id', $id)->update($change);
    DB::table('bookings')->insert($row);
    expect(DB::table('bookings')->where('enforced_one_to_one_slot_id', $row['slot_id'])->count())->toBe(1);
})->with([
    'cancel' => [['status' => 'canceled']],
    'complete' => [['status' => 'completed']],
    'soft delete' => [['deleted_at' => '2026-10-23 12:00:00']],
    'change type' => [['type' => 'group']],
]);

test('existing booking foreign keys reject orphan inserts', function (string $column) {
    $row = rawConstraintBooking([$column => 999999]);
    expect(fn () => DB::table('bookings')->insert($row))->toThrow(QueryException::class);
})->with(['customer_id', 'slot_id', 'resource_id']);

test('migration backfills derived guards and rollback normalizes legacy values', function () {
    $migration = require database_path('migrations/2026_10_23_000000_enforce_active_booking_slot_in_database.php');
    $migration->down();
    $row = rawConstraintBooking();
    $id = DB::table('bookings')->insertGetId($row);
    $migration->up();
    expect(DB::table('bookings')->where('id', $id)->value('enforced_one_to_one_slot_id'))->toBe($row['slot_id']);
    $migration->down();
    expect(DB::table('bookings')->where('id', $id)->value('active_one_to_one_slot_id'))->toBe($row['slot_id']);
    $migration->up();
});

test('migration refuses conflicting reservations before changing the schema', function () {
    $migration = require database_path('migrations/2026_10_23_000000_enforce_active_booking_slot_in_database.php');
    $migration->down();
    $row = rawConstraintBooking();
    DB::table('bookings')->insert([$row, $row]);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Resolve conflicting');
    expect(DB::getSchemaBuilder()->hasColumn('bookings', 'enforced_one_to_one_slot_id'))->toBeFalse();
    DB::table('bookings')->where('type', 'one-on-one')->update(['status' => 'canceled']);
    $migration->up();
});

test('group bookings and distinct slots remain available', function () {
    $row = rawConstraintBooking(['type' => 'group']);
    DB::table('bookings')->insert([$row, $row]);
    DB::table('bookings')->insert(rawConstraintBooking());
    DB::table('bookings')->insert(rawConstraintBooking());
    expect(DB::table('bookings')->whereNotNull('enforced_one_to_one_slot_id')->count())->toBe(2);
});

test('session user foreign key rejects orphan identities', function () {
    expect(fn () => DB::table('sessions')->insert([
        'id' => 'orphan', 'user_id' => 999999, 'payload' => '', 'last_activity' => 0,
    ]))->toThrow(QueryException::class);
});

test('deleting a customer makes their session anonymous', function () {
    $user = Customer::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'retained', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => 0,
    ]);
    DB::table('customers')->where('id', $user->id)->delete();
    expect(DB::table('sessions')->where('id', 'retained')->first())->user_id->toBeNull()->payload->toBe('payload');
});

test('session migration repairs existing orphan identities', function () {
    $migration = require database_path('migrations/2026_10_23_000001_constrain_session_users.php');
    $migration->down();
    DB::table('sessions')->insert([
        'id' => 'legacy', 'user_id' => 999999, 'payload' => 'payload', 'last_activity' => 0,
    ]);
    $migration->up();
    expect(DB::table('sessions')->where('id', 'legacy')->first())->user_id->toBeNull()->payload->toBe('payload');
});

test('session migration refuses a different authentication provider before cleanup', function () {
    $migration = require database_path('migrations/2026_10_23_000001_constrain_session_users.php');
    config()->set('auth.providers.users.model', User::class);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Customer authentication provider');
});

test('active slot migration can resume after adding the generated column', function () {
    $migration = require database_path('migrations/2026_10_23_000000_enforce_active_booking_slot_in_database.php');
    $migration->down();
    DB::getSchemaBuilder()->table('bookings', function (Blueprint $table) {
        $table->unsignedBigInteger('enforced_one_to_one_slot_id')->nullable()->virtualAs(
            "CASE WHEN type = 'one-on-one' AND status IN ('pending', 'confirmed') AND deleted_at IS NULL THEN slot_id ELSE NULL END"
        );
    });
    $migration->up();
    $migration->up();
    expect(DB::getSchemaBuilder()->hasIndex('bookings', 'bookings_enforced_one_to_one_slot_unique'))->toBeTrue();
});

test('deleting a booking cascades its owned documents', function () {
    $booking = Booking::factory()->create();
    DB::table('booking_documents')->insert([
        'booking_id' => $booking->id, 'disk' => 's3', 'key' => 'test.pdf',
        'original_name' => 'test.pdf', 'mime' => 'application/pdf', 'size' => 1,
    ]);
    DB::table('bookings')->where('id', $booking->id)->delete();
    expect(DB::table('booking_documents')->count())->toBe(0);
});
