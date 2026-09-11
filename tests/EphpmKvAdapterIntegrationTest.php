<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony\Tests;

use Ephpm\Cache\Symfony\EphpmKvAdapter;
use Ephpm\Cache\Symfony\InMemoryKvOps;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Tests\Adapter\AdapterTestCase;

/**
 * Runs Symfony's shared cache-pool conformance suite
 * ({@see AdapterTestCase}, which extends the PSR-6
 * `Cache\IntegrationTests\CachePoolTest`) against {@see EphpmKvAdapter}
 * backed by {@see InMemoryKvOps}, so the adapter is proven against the
 * exact contract Symfony holds `RedisAdapter`, `ArrayAdapter`, etc. to —
 * not just this package's hand-written unit tests.
 *
 * A handful of suite cases exercise capabilities the KV SAPI genuinely
 * does not expose; those are declared in {@see $skippedTests} with a
 * reason rather than silently passing/failing. Everything else — round
 * trips of every data type, TTL/default-lifetime expiry, deferred saves,
 * metadata, key validation — runs for real.
 *
 * Note on assertions: Symfony's adapters validate cache keys through
 * `assert()`, so the invalid-key conformance cases only reject bad keys
 * when assertions are enabled. Run with `php -d zend.assertions=1
 * vendor/bin/phpunit` (or `composer test`) to exercise them; under the
 * default production INI (`zend.assertions=-1`) they self-skip, exactly
 * as Symfony's own AdapterTestCase already does for its sibling
 * `*InvalidKeys` cases.
 */
final class EphpmKvAdapterIntegrationTest extends AdapterTestCase
{
    /**
     * A single KV backend shared by every pool created within one test.
     * Production ephpm_kv_* is process-global, so two adapter instances see
     * each other's writes; the in-memory double must model that (the suite's
     * "force a new pool instance, data still there" cases depend on it).
     */
    private ?InMemoryKvOps $sharedOps = null;

    protected function setUp(): void
    {
        // Do NOT reset $this->sharedOps here: the base CachePoolTest builds
        // $this->cache through createCachePool() in its own #[Before] hook,
        // which may run before this setUp(). Reassigning would give $this->cache
        // a different backend than later createCachePool() calls, breaking the
        // "new pool instance sees prior writes" cases. The property is null on a
        // fresh test object (PHPUnit makes one per test), and createCachePool()
        // lazily creates the single shared instance via `??=`.
        parent::setUp();

        // Prefix ("scoped") clearing needs key enumeration (Redis SCAN /
        // SQL LIKE). The ephpm_kv_* SAPI has no SCAN, so a namespaced clear
        // is deliberately a no-op that returns false (see
        // EphpmKvAdapter::doClear and the README "Limitations"). These cases
        // assert the prefix was cleared, which we cannot honour.
        $this->skippedTests['testClearPrefix'] =
            'ephpm_kv_* exposes no SCAN, so prefix/namespace clearing is unsupported by design.';
        $this->skippedTests['testClearPrefixWithUnderscore'] =
            'ephpm_kv_* exposes no SCAN, so prefix/namespace clearing is unsupported by design.';

        // testNamespaces requires the pool to implement
        // Symfony\Contracts\Cache\NamespacedPoolInterface (withSubNamespace).
        // This adapter does not; namespace isolation is achieved through the
        // constructor namespace + AbstractAdapter key prefixing instead.
        $this->skippedTests['testNamespaces'] =
            'EphpmKvAdapter does not implement NamespacedPoolInterface (no withSubNamespace()).';

        // AdapterTestCase::setUp() already self-skips the *InvalidKeys cases
        // when assertions are off ("Keys are checked only when assert() is
        // enabled."). The cache/integration-tests suite added parallel
        // *InvalidKeyTypes cases (and testDeleteItemsValidatesEveryKeyBefore-
        // Mutation) that Symfony's 7.4 AdapterTestCase does not yet skip;
        // they share the exact same assert()-gated key validation, so extend
        // the same rule to them.
        if (!self::assertionsEnabled()) {
            $reason = 'Cache keys are validated via assert(); run with zend.assertions=1 to exercise this.';
            $this->skippedTests['testGetItemInvalidKeyTypes'] = $reason;
            $this->skippedTests['testGetItemsInvalidKeyTypes'] = $reason;
            $this->skippedTests['testHasItemInvalidKeyTypes'] = $reason;
            $this->skippedTests['testDeleteItemInvalidKeyTypes'] = $reason;
            $this->skippedTests['testDeleteItemsInvalidKeyTypes'] = $reason;
        }

        // testPrune is auto-skipped by AdapterTestCase::setUp() because the
        // adapter is not PruneableInterface (the KV store expires lazily and
        // has no enumerable prune step).
    }

    /**
     * Same assert()-gated key validation as the *InvalidKeyTypes cases, but
     * this one has no $skippedTests guard upstream, so it needs an override to
     * self-skip under the default (assertions-off) INI.
     */
    public function testDeleteItemsValidatesEveryKeyBeforeMutation(): void
    {
        if (!self::assertionsEnabled()) {
            self::markTestSkipped(
                'Cache keys are validated via assert(); run with zend.assertions=1 to exercise this.'
            );
        }

        parent::testDeleteItemsValidatesEveryKeyBeforeMutation();
    }

    public function createCachePool(int $defaultLifetime = 0, ?string $testMethod = null): CacheItemPoolInterface
    {
        $this->sharedOps ??= new InMemoryKvOps();

        return new EphpmKvAdapter($testMethod ?? '', $defaultLifetime, $this->sharedOps);
    }

    private static function assertionsEnabled(): bool
    {
        try {
            \assert(false, new \Exception());
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
