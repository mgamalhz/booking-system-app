<?php

namespace App\Services;

use App\Data\AvailabilityCriteria;
use App\Models\Resource;
use App\Services\Contracts\AvailabilityCacheInterface;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Psr\Log\LoggerInterface;
use Throwable;

final class RedisAvailabilityCache implements AvailabilityCacheInterface
{
    public function __construct(private readonly CacheFactory $cache, private readonly LoggerInterface $logger) {}

    public function remember(Resource $resource, AvailabilityCriteria $criteria, array $slots): array
    {
        $startedAt = microtime(true);
        try {
            return $this->rememberSafely($resource, $criteria, $slots, $startedAt);
        } catch (Throwable $exception) {
            return $this->recover($exception, $resource, $slots, $startedAt);
        }
    }

    private function rememberSafely(Resource $resource, AvailabilityCriteria $criteria, array $slots, float $startedAt): array
    {
        $cache = $this->cache->store();
        $key = $this->key($resource, $criteria, (int) $cache->get(AvailabilityCacheKey::scheduleVersionKey(), 1));
        $tagged = $cache->tags([AvailabilityCacheKey::resourceTag($resource->id)]);

        return $this->cachedOrLocked($cache->getStore(), $tagged, $key, $resource->id, $slots, $startedAt);
    }

    private function cachedOrLocked(object $store, TaggedCache $cache, string $key, int $resourceId, array $slots, float $startedAt): array
    {
        $cached = $this->cached($cache, $key);

        return $cached === null
            ? $this->rememberLocked($store, $cache, $key, $resourceId, $slots, $startedAt)
            : $this->hit($cached, 'hit', $resourceId, $startedAt);
    }

    private function rememberLocked(object $store, TaggedCache $cache, string $key, int $resourceId, array $slots, float $startedAt): array
    {
        throw_unless($store instanceof LockProvider, \LogicException::class, 'The default cache store must support locks.');

        return $store->lock($key.':fill', (int) config('booking.availability_cache.lock_seconds', 10))
            ->block((int) config('booking.availability_cache.lock_wait_seconds', 2),
                fn (): array => $this->fill($cache, $key, $resourceId, $slots, $startedAt));
    }

    private function fill(TaggedCache $cache, string $key, int $resourceId, array $slots, float $startedAt): array
    {
        $cached = $this->cached($cache, $key);
        if ($cached !== null) {
            return $this->hit($cached, 'hit_after_wait', $resourceId, $startedAt);
        }
        $this->write($cache, $key, $slots, $resourceId);

        return $this->hit($slots, 'miss', $resourceId, $startedAt);
    }

    private function write(TaggedCache $cache, string $key, array $result, int $resourceId): void
    {
        rescue(fn () => $cache->put($key, $result, (int) config('booking.availability_cache.ttl_seconds', 120)),
            fn (Throwable $exception) => $this->logFailure('write_failed', $exception, $resourceId), false);
    }

    private function recover(Throwable $exception, Resource $resource, array $slots, float $startedAt): array
    {
        $result = $exception instanceof LockTimeoutException ? 'stampede_fallback' : 'bypassed';
        $this->logFailure($result, $exception, $resource->id);

        return $this->hit($slots, $result, $resource->id, $startedAt);
    }

    private function key(Resource $resource, AvailabilityCriteria $criteria, int $scheduleVersion): string
    {
        return AvailabilityCacheKey::make($resource->id, $criteria->startDate, $criteria->endDate,
            $criteria->timezone, $criteria->filters, (string) config('booking.availability_cache.version', 'v1'), $scheduleVersion);
    }

    private function cached(TaggedCache $cache, string $key): ?array
    {
        $value = $cache->get($key);

        return is_array($value) ? $value : null;
    }

    private function hit(array $result, string $metric, int $resourceId, float $startedAt): array
    {
        $this->logger->info('availability_cache.access', ['result' => $metric, 'resource_id' => $resourceId] +
            ['latency_ms' => round((microtime(true) - $startedAt) * 1000, 2)]);

        return $result;
    }

    private function logFailure(string $reason, Throwable $exception, int $resourceId): void
    {
        $this->logger->warning('availability_cache.'.$reason,
            ['resource_id' => $resourceId, 'exception' => $exception::class]);
    }
}
