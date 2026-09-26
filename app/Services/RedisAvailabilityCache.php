<?php

namespace App\Services;

use App\Data\AvailabilityCriteria;
use App\Services\Contracts\AvailabilityCacheInterface;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class RedisAvailabilityCache implements AvailabilityCacheInterface
{
    public function __construct(private readonly CacheFactory $cache, private readonly LoggerInterface $logger) {}

    public function remember(
        int $resourceId,
        AvailabilityCriteria $criteria,
        Closure $resolveSlots): array
    {
        $startedAt = microtime(true);
        $load = function () use ($resolveSlots): array {
            try {
                return $resolveSlots();
            } catch (Throwable $exception) {
                throw new AvailabilityResolverFailed($exception);
            }
        };

        try {
            return $this->rememberSafely($resourceId, $criteria, $load, $startedAt);
        } catch (AvailabilityResolverFailed $exception) {
            throw $exception->reason();
        } catch (Throwable $exception) {
            return $this->recover($exception, $resourceId, $resolveSlots, $startedAt);
        }
    }

    private function rememberSafely(int $resourceId, AvailabilityCriteria $criteria, Closure $resolveSlots, float $startedAt): array
    {
        $cache = $this->cache->store();
        throw_unless($cache->supportsTags(), \LogicException::class, 'The default cache store must support cache tags.');

        $taggedCache = $cache->tags([
            AvailabilityCacheKey::scheduleTag(),
            AvailabilityCacheKey::resourceTag($resourceId),
        ]);
        $key = $this->key($resourceId, $criteria);

        return $this->cachedOrLocked($cache->getStore(), $taggedCache, $key, $resourceId, $resolveSlots, $startedAt);
    }

    private function cachedOrLocked(object $store, CacheRepository $cache, string $key, int $resourceId, Closure $resolveSlots, float $startedAt): array
    {
        $cached = $this->cached($cache, $key);

        return $cached === null
            ? $this->rememberLocked($store, $cache, $key, $resourceId, $resolveSlots, $startedAt)
            : $this->hit($cached, 'hit', $resourceId, $startedAt);
    }

    private function rememberLocked(object $store, CacheRepository $cache, string $key, int $resourceId, Closure $resolveSlots, float $startedAt): array
    {
        throw_unless($store instanceof LockProvider, \LogicException::class, 'The default cache store must support locks.');

        return $store->lock($key.':fill', (int) config('booking.availability_cache.lock_seconds', 10))
            ->block((int) config('booking.availability_cache.lock_wait_seconds', 2),
                fn (): array => $this->fill($cache, $key, $resourceId, $resolveSlots, $startedAt));
    }

    private function fill(CacheRepository $cache, string $key, int $resourceId, Closure $resolveSlots, float $startedAt): array
    {
        $cached = $this->cached($cache, $key);
        if ($cached !== null) {
            return $this->hit($cached, 'hit_after_wait', $resourceId, $startedAt);
        }

        $slots = $resolveSlots();
        $this->write($cache, $key, $slots, $resourceId);

        return $this->hit($slots, 'miss', $resourceId, $startedAt);
    }

    private function write(CacheRepository $cache, string $key, array $result, int $resourceId): void
    {
        rescue(fn () => $cache->put($key, $result, (int) config('booking.availability_cache.ttl_seconds', 120)),
            fn (Throwable $exception) => $this->logFailure('write_failed', $exception, $resourceId), false);
    }

    private function recover(Throwable $exception, int $resourceId, Closure $resolveSlots, float $startedAt): array
    {
        $result = $exception instanceof LockTimeoutException ? 'stampede_fallback' : 'bypassed';
        $this->logFailure($result, $exception, $resourceId);

        $slots = $resolveSlots();

        return $this->hit($slots, $result, $resourceId, $startedAt);
    }

    private function key(int $resourceId, AvailabilityCriteria $criteria): string
    {
        return AvailabilityCacheKey::make($resourceId, $criteria->startDate, $criteria->endDate,
            $criteria->timezone, $criteria->filters);
    }

    private function cached(CacheRepository $cache, string $key): ?array
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

final class AvailabilityResolverFailed extends RuntimeException
{
    public function __construct(private readonly Throwable $reason)
    {
        parent::__construct($reason->getMessage(), 0, $reason);
    }

    public function reason(): Throwable
    {
        return $this->reason;
    }
}
