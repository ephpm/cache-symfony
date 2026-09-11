<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony;

/**
 * Backend that calls the global `ephpm_kv_*` functions registered by
 * the ePHPm SAPI. Refuses to construct if those functions aren't present
 * so we fail fast outside the runtime instead of producing
 * "Call to undefined function" errors at request time.
 */
final class SapiKvOps implements KvOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_kv_get')) {
            throw new \RuntimeException(
                'ephpm KV SAPI functions are not available. '
                . 'This adapter only works inside the ePHPm runtime; '
                . 'use Ephpm\\Cache\\Symfony\\InMemoryKvOps in tests.'
            );
        }
    }

    public function get(string $key): ?string
    {
        /** @var string|null */
        return \ephpm_kv_get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_set($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        return (int) \ephpm_kv_del($key);
    }

    public function exists(string $key): bool
    {
        return (bool) \ephpm_kv_exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        // ephpm_kv_incr_by returns int on success, or false when the stored
        // value is not an integer. A bare `(int) false === 0` cast would
        // silently swallow that error and report a bogus counter value, so
        // detect false explicitly and propagate as an exception (the
        // KvOpsInterface::incrBy contract says it throws).
        $result = \ephpm_kv_incr_by($key, $delta);
        if ($result === false) {
            throw new \RuntimeException(
                "ephpm_kv_incr_by failed for key \"{$key}\": stored value is not an integer"
            );
        }
        return (int) $result;
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (bool) \ephpm_kv_expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        return (int) \ephpm_kv_ttl($key);
    }

    public function pttl(string $key): int
    {
        return (int) \ephpm_kv_pttl($key);
    }

    public function flush(): bool
    {
        if (!\function_exists('ephpm_kv_flush_all')) {
            return false;
        }
        return (bool) \ephpm_kv_flush_all();
    }
}
