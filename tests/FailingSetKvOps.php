<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony\Tests;

use Ephpm\Cache\Symfony\InMemoryKvOps;
use Ephpm\Cache\Symfony\KvOpsInterface;

/**
 * Test double that behaves like {@see InMemoryKvOps} but makes `set()`
 * report failure (as the SAPI does on OOM under noeviction). Used to
 * prove {@see \Ephpm\Cache\Symfony\EphpmKvAdapter::doSave()} collects and
 * returns the ids it could not persist instead of claiming success.
 */
final class FailingSetKvOps implements KvOpsInterface
{
    private InMemoryKvOps $inner;

    /**
     * @param bool $failAll when true, every set() fails; otherwise only the
     *                      first `$failFirstN` sets fail
     */
    public function __construct(
        private bool $failAll = true,
        private int $failFirstN = 0,
    ) {
        $this->inner = new InMemoryKvOps();
    }

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        if ($this->failAll || $this->failFirstN > 0) {
            if (!$this->failAll) {
                --$this->failFirstN;
            }

            return false;
        }

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
}
