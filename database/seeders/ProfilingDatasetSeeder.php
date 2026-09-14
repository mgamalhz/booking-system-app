<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfilingDatasetSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $password = Hash::make('profile-password');
        $customer = Customer::query()->updateOrCreate(
            ['email' => 'profile@example.test'],
            ['name' => 'Profile Customer', 'password' => $password, 'phone' => '+10000000000',
                'address' => '1 Profile Way', 'city' => 'Cairo', 'state' => 'Cairo', 'zip' => '00000', 'country' => 'EG']
        );

        Customer::factory()->count(249)->create();
        $resources = Resource::factory()->count(50)->create();

        $slots = [];
        $start = CarbonImmutable::today()->addDay();
        for ($i = 0; $i < 5000; $i++) {
            $hour = 8 + ($i % 10);
            $slots[] = ['date' => $start->addDays(intdiv($i, 10))->toDateString(),
                'start_time' => sprintf('%02d:00:00', $hour), 'end_time' => sprintf('%02d:30:00', $hour),
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($slots, 500) as $chunk) {
            DB::table('slots')->insert($chunk);
        }

        $slotIds = Slot::query()->orderBy('id')->limit(3000)->pluck('id');
        $resourceIds = $resources->pluck('id')->values();
        $bookings = [];
        foreach ($slotIds as $index => $slotId) {
            $bookings[] = ['customer_id' => $customer->id, 'resource_id' => $resourceIds[$index % $resourceIds->count()],
                'slot_id' => $slotId, 'status' => $index % 4 === 0 ? 'pending' : 'confirmed',
                'type' => 'one-on-one', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($bookings, 500) as $chunk) {
            DB::table('bookings')->insert($chunk);
        }
    }
}
