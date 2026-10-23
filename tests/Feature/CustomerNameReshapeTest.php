<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function legacyCustomer(string $name): int
{
    return DB::table('customers')->insertGetId(['name' => $name, 'email' => uniqid().'@example.test']);
}

it('dual writes and preserves the public customer shape', function () {
    $customer = Customer::factory()->create(['name' => 'ليلى عبد الرحمن']);
    expect($customer->given_name)->toBe('ليلى')->and($customer->family_name)->toBe('عبد الرحمن')
        ->and($customer->toArray())->not->toHaveKeys(['given_name', 'family_name']);
    $customer->update(['name' => 'Prince']);
    expect($customer->fresh()->billingName())->toBe(['given_name' => 'Prince', 'family_name' => '']);
});

it('reads safely after an old writer changes a previously backfilled name', function () {
    Log::spy();
    $customer = Customer::factory()->create(['name' => 'Old Name']);
    DB::table('customers')->where('id', $customer->id)->update(['name' => 'New Person']);
    expect($customer->fresh()->billingName())->toBe(['given_name' => 'New', 'family_name' => 'Person']);
    Log::shouldHaveReceived('info')->with('customer_name.legacy_fallback', ['customer_id' => $customer->id])->once();
    $this->artisan('customers:backfill-names')->assertSuccessful();
    expect($customer->fresh()->given_name)->toBe('New');
});

it('backfills bounded chunks, resumes and is idempotent with redacted verification', function () {
    $first = legacyCustomer('  Ada Lovelace  ');
    $second = legacyCustomer('Prince');
    $third = legacyCustomer('ليلى عبد الرحمن');
    expect(Artisan::call('customers:backfill-names', ['--chunk' => 1, '--max-rows' => 2]))->toBe(0);
    $report = json_decode(Artisan::output(), true);
    expect($report['updated'])->toBe(2)->and($report['last_id'])->toBe($second)->and($report['remaining_in_snapshot'])->toBe(1);
    $this->artisan('customers:backfill-names', ['--after' => $second, '--chunk' => 1])->assertSuccessful();
    expect(Artisan::call('customers:backfill-names'))->toBe(0);
    expect(json_decode(Artisan::output(), true)['updated'])->toBe(0);
    expect(Artisan::call('customers:backfill-names', ['--verify' => true, '--chunk' => 1]))->toBe(0);
    $output = Artisan::output();
    $report = json_decode($output, true);
    expect($report['scanned'])->toBe(3)->and($report['matched'])->toBe(3)
        ->and($report['pending'])->toBe(0)->and($report['errors'])->toBe(0)
        ->and($output)->not->toContain('Ada', 'Prince', 'ليلى');
    expect(DB::table('customers')->where('id', $first)->value('name'))->toBe('  Ada Lovelace  ');
    expect(DB::table('customers')->where('id', $third)->value('family_name'))->toBe('عبد الرحمن');
});

it('logs errors without leaking names and resumes before the failed row', function () {
    Log::spy();
    $first = legacyCustomer('Good Name');
    $bad = legacyCustomer('   ');
    $last = legacyCustomer('Last Name');
    expect(Artisan::call('customers:backfill-names'))->toBe(1);
    $report = json_decode(Artisan::output(), true);
    expect($report['last_id'])->toBe($first)->and($report['failed_id'])->toBe($bad)->and($report['errors'])->toBe(1);
    expect(DB::table('customers')->where('id', $last)->value('given_name'))->toBeNull();
    Log::shouldHaveReceived('error')->with('customer_name.backfill_failed', ['customer_id' => $bad, 'exception_class' => InvalidArgumentException::class])->once();
    DB::table('customers')->where('id', $bad)->update(['name' => 'Fixed Name']);
    $this->artisan('customers:backfill-names', ['--after' => $report['last_id']])->assertSuccessful();
    $this->artisan('customers:backfill-names', ['--verify' => true])->assertSuccessful();
});

it('verification reports missing or stale shapes without writing', function () {
    $id = legacyCustomer('Missing Shape');
    expect(Artisan::call('customers:backfill-names', ['--verify' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['pending'])->toBe(1);
    expect(DB::table('customers')->where('id', $id)->value('given_name'))->toBeNull();
});

it('rejects unsafe cursor and chunk arguments', function () {
    $this->artisan('customers:backfill-names', ['--chunk' => 0])->assertFailed();
    $this->artisan('customers:backfill-names', ['--max-rows' => -1])->assertFailed();
    $this->artisan('customers:backfill-names', ['--verify' => true, '--after' => 1])->assertFailed();
});
