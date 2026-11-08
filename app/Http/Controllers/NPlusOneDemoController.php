<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NPlusOneDemoController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(app()->environment('local', 'testing'), 404);
        $input = $request->validate([
            'count' => 'sometimes|integer|in:5,20,50',
            'mode' => 'sometimes|in:lazy,eager,count',
        ]);
        $count = (int) ($input['count'] ?? 5);
        $mode = $input['mode'] ?? 'lazy';

        // A disposable database: these demonstrations never read or write real bookings.
        config(['database.connections.n_plus_one_demo' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('n_plus_one_demo');
        $db = DB::connection('n_plus_one_demo');
        try {
            $db->statement('CREATE TABLE bookings (id INTEGER PRIMARY KEY, customer_id INTEGER, resource_id INTEGER, slot_id INTEGER, deleted_at TEXT)');
            $db->statement('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');
            $db->statement('CREATE TABLE resources (id INTEGER PRIMARY KEY, name TEXT)');
            $db->statement('CREATE TABLE slots (id INTEGER PRIMARY KEY, start_time TEXT, end_time TEXT)');
            $db->statement('CREATE TABLE booking_documents (id INTEGER PRIMARY KEY, booking_id INTEGER, deleted_at TEXT)');
            for ($id = 1; $id <= 50; $id++) {
                $db->table('customers')->insert(['id' => $id, 'name' => "Customer $id"]);
                $db->table('resources')->insert(['id' => $id, 'name' => "Resource $id"]);
                $db->table('slots')->insert(['id' => $id, 'start_time' => '09:00:00', 'end_time' => '10:00:00']);
                $db->table('bookings')->insert(['id' => $id, 'customer_id' => $id, 'resource_id' => $id, 'slot_id' => $id]);
                for ($document = 0; $document < 3; $document++) {
                    $db->table('booking_documents')->insert(['booking_id' => $id]);
                }
            }

            $db->flushQueryLog();
            $db->enableQueryLog();
            $started = hrtime(true);
            // Override Booking::$with only for this isolated teaching query.
            $query = Booking::on('n_plus_one_demo')->without(['customer', 'resource', 'slot'])
                ->select(['id', 'customer_id', 'resource_id', 'slot_id'])->orderBy('id')->limit($count);
            if ($mode === 'eager') {
                $query->with(['customer:id,name', 'resource:id,name', 'slot:id,start_time,end_time']);
            } elseif ($mode === 'count') {
                $query->withCount('documents');
            }
            $rows = $query->get()->map(function (Booking $booking) use ($mode) {
                if ($mode === 'count') {
                    return ['id' => $booking->id, 'documents_count' => $booking->documents_count];
                }

                return ['id' => $booking->id, 'customer' => $booking->customer->name,
                    'resource' => $booking->resource->name, 'start_time' => $booking->slot->start_time,
                    'end_time' => $booking->slot->end_time];
            })->all();
            $elapsed = round((hrtime(true) - $started) / 1e6, 2);
            $queries = $db->getQueryLog();
            $data = compact('count', 'mode', 'rows', 'queries', 'elapsed');
            $data['query_count'] = count($queries);
            $data['sql_ms'] = round(array_sum(array_column($queries, 'time')), 2);

            return $request->wantsJson() ? response()->json($data) : view('demo.n-plus-one', $data);
        } finally {
            $db->disableQueryLog();
            DB::purge('n_plus_one_demo');
        }
    }
}
