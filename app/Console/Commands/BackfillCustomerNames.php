<?php

namespace App\Console\Commands;

use App\Support\CustomerName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillCustomerNames extends Command
{
    protected $signature = 'customers:backfill-names
        {--after=0 : Last successfully processed ID; restart at zero for a reconciliation sweep}
        {--chunk=100 : IDs fetched at once (1-1000)}
        {--max-rows=1000 : Maximum rows processed per invocation}
        {--verify : Read-only full verification, chunked by ID}';

    protected $description = 'Resumably split customer names, or verify counts and redacted samples';

    public function handle(): int
    {
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('max-rows'), FILTER_VALIDATE_INT);
        if ($after === false || $after < 0 || $chunk === false || $chunk < 1 || $chunk > 1000 || $limit === false || $limit < 1) {
            $this->error('Require after >= 0, chunk 1-1000, and max-rows >= 1.');

            return self::FAILURE;
        }
        if ($this->option('verify') && $after !== 0) {
            $this->error('Verification must start at zero.');

            return self::FAILURE;
        }

        $upper = (int) DB::table('customers')->max('id');
        $report = ['upper_id' => $upper, 'scanned' => 0, 'updated' => 0, 'matched' => 0,
            'pending' => 0, 'errors' => 0, 'last_id' => $after, 'samples' => []];
        $verify = (bool) $this->option('verify');
        while ($verify || $report['scanned'] < $limit) {
            $size = $verify ? $chunk : min($chunk, $limit - $report['scanned']);
            $ids = DB::table('customers')->where('id', '>', $report['last_id'])
                ->where('id', '<=', $upper)->orderBy('id')->limit($size)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            foreach ($ids as $id) {
                try {
                    // Short per-row transactions serialize with old and new writers.
                    $result = DB::transaction(function () use ($id, $verify): ?bool {
                        $query = DB::table('customers')->where('id', $id);
                        $row = ($verify ? $query : $query->lockForUpdate())->first();
                        if ($row === null) {
                            return null;
                        }
                        $parts = CustomerName::split($row->name);
                        $matches = CustomerName::matches($row, $parts);
                        if (! $verify && ! $matches) {
                            DB::table('customers')->where('id', $id)->update($parts);
                        }

                        return $matches;
                    }, 3);
                    $report['scanned']++;
                    if ($result !== null) {
                        $report[$result ? 'matched' : ($verify ? 'pending' : 'updated')]++;
                        if (count($report['samples']) < 5) {
                            $report['samples'][] = ['id' => $id, 'matched_before' => $result];
                        }
                    }
                } catch (Throwable $exception) {
                    $report['errors']++;
                    // No customer names, SQL, or exception messages in logs.
                    Log::error('customer_name.backfill_failed', ['customer_id' => $id, 'exception_class' => $exception::class]);
                    if (! $verify) {
                        $report['failed_id'] = $id;
                        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

                        return self::FAILURE; // Cursor stays before failed row.
                    }
                }
                $report['last_id'] = $id;
            }
        }
        $report['remaining_in_snapshot'] = DB::table('customers')->where('id', '>', $report['last_id'])->where('id', '<=', $upper)->count();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['errors'] > 0 || ($verify && $report['pending'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
