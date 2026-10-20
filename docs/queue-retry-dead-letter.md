# Queue Retry And Dead-Letter Strategy

## Storage

The application uses the database queue driver:

```env
QUEUE_CONNECTION=database
QUEUE_FAILED_DRIVER=database-uuids
DB_QUEUE_RETRY_AFTER=180
FAILED_JOB_ALERT_MAIL_TO=ops@example.com
```

The default Laravel jobs migration already creates:

- `jobs`
- `job_batches`
- `failed_jobs`

Run migrations before starting workers:

```bash
php artisan migrate
```

## Worker Defaults

Use bounded global defaults for jobs that do not define their own retry policy:

```bash
php artisan queue:work --queue=bookings,default --timeout=130 --tries=3 --backoff=10
```

The local `composer dev` queue worker uses the same defaults.

## Per-Job Policy

Critical booking notifications override the worker defaults:

| Job | Tries | Backoff |
| --- | ---: | --- |
| `BookingConfirmationNotification` | 5 | 10s, 30s, 60s, 120s |
| `BookingReminderNotification` | 3 | 60s, 300s, 900s |

Both notifications implement `failed(Throwable $e)` and log structured replay context, including the job class, booking ID, customer details, exception class, and exception message.

## Alerts

`App\Listeners\SendFailedJobAlert` listens to `Illuminate\Queue\Events\JobFailed`.

When a job lands in `failed_jobs`, it:

- logs a `critical` entry with queue, job ID, UUID, resolved job name, attempts, and exception details;
- sends `App\Notifications\FailedQueueJobNotification` by mail when `FAILED_JOB_ALERT_MAIL_TO` is set.

In local development, `MAIL_MAILER=log` writes the alert email to the Laravel log.

## Verification

The retry/dead-letter strategy is covered by Pest unit tests:

```bash
php artisan test --filter QueueFailureStrategyTest
```

The tests verify:

- confirmation and reminder notifications define bounded `$tries` and `$backoff`;
- confirmation and reminder `failed(Throwable $e)` hooks write structured replay context;
- `SendFailedJobAlert` handles Laravel's `JobFailed` event with `Notification::fake()` and logs critical queue context.

## Replay A Dead-Lettered Job

List failed jobs:

```bash
php artisan queue:failed
```

Retry the failed job using the ID from `queue:failed`:

```bash
php artisan queue:retry {id}
```

After fixing the underlying cause, the retried job should complete and `php artisan queue:failed` should not show a new failure for the replayed job.
