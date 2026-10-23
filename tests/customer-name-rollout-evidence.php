<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Reproducible, synthetic evidence. Always uses an isolated in-memory database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'telescope.enabled' => false]);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
for ($i = 1; $i <= 257; $i++) {
    DB::table('customers')->insert([
        'name' => ['Ada Lovelace', 'Prince', 'ليلى عبد الرحمن'][$i % 3],
        'email' => "synthetic-$i@example.test",
    ]);
}
$run = function (string $stage, array $options = []): array {
    $exit = Artisan::call('customers:backfill-names', $options);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['stage' => $stage, 'exit' => $exit, 'report' => $report], JSON_THROW_ON_ERROR).PHP_EOL;
    if ($exit !== ($stage === 'detect-legacy-write' ? 1 : 0)) {
        throw new RuntimeException('Unexpected command result at '.$stage);
    }

    return $report;
};
$cursor = 0;
do {
    $report = $run('backfill', ['--after' => $cursor, '--chunk' => 17, '--max-rows' => 64]);
    $cursor = $report['last_id'];
} while ($report['remaining_in_snapshot'] > 0);
$run('idempotent-rerun');
$run('verify-initial', ['--verify' => true, '--chunk' => 17]);
DB::table('customers')->where('id', 1)->update(['name' => 'Changed By Legacy']);
$run('detect-legacy-write', ['--verify' => true]);
$run('reconcile');
$run('verify-final', ['--verify' => true]);
