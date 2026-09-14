<?php

use Laravel\Telescope\Http\Middleware\Authorize;
use Laravel\Telescope\Watchers;

return [
    // Opt-in everywhere, including local: profiling must never be enabled accidentally.
    'enabled' => filter_var(env('TELESCOPE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'domain' => env('TELESCOPE_DOMAIN'),
    'path' => env('TELESCOPE_PATH', 'internal/telescope'),
    'driver' => 'database',
    'storage' => [
        'database' => ['connection' => env('TELESCOPE_DB_CONNECTION', env('DB_CONNECTION')), 'chunk' => 1000],
    ],
    'queue' => ['connection' => env('TELESCOPE_QUEUE_CONNECTION'), 'queue' => env('TELESCOPE_QUEUE')],
    'middleware' => ['web', Authorize::class],
    'allowed_emails' => env('TELESCOPE_ALLOWED_EMAILS', ''),
    'only_paths' => ['api/*'],
    'ignore_paths' => ['internal/telescope*'],
    'ignore_commands' => [],
    'watchers' => [
        Watchers\CacheWatcher::class => env('TELESCOPE_CACHE_WATCHER', true),
        Watchers\CommandWatcher::class => false,
        Watchers\DumpWatcher::class => false,
        Watchers\EventWatcher::class => false,
        Watchers\ExceptionWatcher::class => true,
        Watchers\HttpClientWatcher::class => true,
        Watchers\JobWatcher::class => true,
        Watchers\LogWatcher::class => ['enabled' => true, 'level' => 'error'],
        Watchers\ModelWatcher::class => false,
        Watchers\NotificationWatcher::class => false,
        Watchers\QueryWatcher::class => ['enabled' => true, 'slow' => 100],
        Watchers\RedisWatcher::class => env('TELESCOPE_REDIS_WATCHER', true),
        Watchers\RequestWatcher::class => ['enabled' => true, 'size_limit' => 32],
        Watchers\ScheduleWatcher::class => false,
        Watchers\ViewWatcher::class => false,
    ],
];
