<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony\Tests;

use Ephpm\Cache\Symfony\ExtendedKvOpsInterface;
use Ephpm\Cache\Symfony\InMemoryKvOps;

/**
 * Test spy: delegates to {@see InMemoryKvOps} and records which backend
 * method each adapter call reached, so tests can tell the multi-key path
 * from the per-key fallback.
 */
final class RecordingKvOps implements ExtendedKvOpsInterface
{
    /** @var list<string> */
    public array $calls = [];

    public InMemoryKvOps $inner;

    /** When true, mget()/mset() fail like a refused SAPI call. */
    public bool $failMulti = false;

    /**
     * @param list<string>|null $ops
     */
    public function __construct(?array $ops = null)
    {
        $this->inner = new InMemoryKvOps($ops);
    }

    public function supports(string $op): bool
    {
        return $this->inner->supports($op);
    }

    public function get(string $key): ?string
    {
        $this->calls[] = 'get';
        return $this->inner->get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        $this->calls[] = 'set';
        return $this->inner->set($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        return $this->inner->del($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        return $this->inner->incrBy($key, $delta);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return $this->inner->expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        return $this->inner->ttl($key);
    }

    public function pttl(string $key): int
    {
        return $this->inner->pttl($key);
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }

    public function mget(array $keys): array|false
    {
        $this->calls[] = 'mget';
        return $this->failMulti ? false : $this->inner->mget($keys);
    }

    public function mset(array $values, int $ttlSeconds = 0): bool
    {
        $this->calls[] = 'mset';
        return $this->failMulti ? false : $this->inner->mset($values, $ttlSeconds);
    }
}
