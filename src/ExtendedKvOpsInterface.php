<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony;

/**
 * Optional multi-key operations backed by `ephpm_kv_mget` / `ephpm_kv_mset`
 * (ePHPm v0.13.0+).
 *
 * Kept separate from {@see KvOpsInterface} so existing custom backends keep
 * compiling. {@see EphpmKvAdapter} calls a method here only after
 * {@see supports()} confirmed it; otherwise it uses its per-key code path.
 */
interface ExtendedKvOpsInterface extends KvOpsInterface
{
    /**
     * Operation names {@see supports()} may be asked about — each is the
     * suffix of the backing SAPI function (`mget` = `ephpm_kv_mget`).
     */
    public const OPS = ['mget', 'mset'];

    /**
     * Whether the operation named `$op` (one of {@see OPS}) is available.
     */
    public function supports(string $op): bool;

    /**
     * `ephpm_kv_mget`.
     *
     * @param list<string> $keys
     *
     * @return list<string|null>|false values aligned with `$keys` (null =
     *                                 missing); false on failure
     */
    public function mget(array $keys): array|false;

    /**
     * `ephpm_kv_mset`: write every pair with the same TTL (0 = no expiry).
     * On a refusal (memory limit) it stops and returns false; earlier keys
     * may already be stored.
     *
     * @param array<array-key, string> $values key => value
     */
    public function mset(array $values, int $ttlSeconds = 0): bool;
}
