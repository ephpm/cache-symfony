<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony\Tests;

use Ephpm\Cache\Symfony\EphpmKvAdapter;
use Ephpm\Cache\Symfony\InMemoryKvOps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * doFetch()/doSave() on the `ephpm_kv_mget` / `ephpm_kv_mset` path
 * (ePHPm v0.13+) and their per-key fallbacks.
 */
#[CoversClass(EphpmKvAdapter::class)]
#[CoversClass(InMemoryKvOps::class)]
final class MultiKeyOpsTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function saveAll(EphpmKvAdapter $adapter, RecordingKvOps $ops, array $values, ?int $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $item = $adapter->getItem($key);
            $item->set($value);
            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }
            $adapter->saveDeferred($item);
        }
        // getItem() above fetched; record only what commit() does.
        $ops->calls = [];
        return $adapter->commit();
    }

    public function test_get_items_uses_one_mget_and_omits_misses(): void
    {
        $ops = new RecordingKvOps();
        $adapter = new EphpmKvAdapter('app', 0, $ops);
        $this->saveAll($adapter, $ops, ['a' => ['x' => 1], 'b' => 2]);
        $ops->calls = [];

        $items = \iterator_to_array($adapter->getItems(['a', 'missing', 'b']));
        self::assertTrue($items['a']->isHit());
        self::assertSame(['x' => 1], $items['a']->get());
        self::assertFalse($items['missing']->isHit());
        self::assertSame(2, $items['b']->get());
        self::assertSame(['mget'], $ops->calls);
    }

    public function test_fetch_falls_back_to_get_without_mget(): void
    {
        $ops = new RecordingKvOps([]);
        $adapter = new EphpmKvAdapter('', 0, $ops);
        $this->saveAll($adapter, $ops, ['a' => 1, 'b' => 2]);
        $ops->calls = [];

        $items = \iterator_to_array($adapter->getItems(['a', 'b']));
        self::assertSame(1, $items['a']->get());
        self::assertSame(['get', 'get'], $ops->calls);
    }

    public function test_fetch_falls_back_when_mget_fails(): void
    {
        $ops = new RecordingKvOps();
        $adapter = new EphpmKvAdapter('', 0, $ops);
        $this->saveAll($adapter, $ops, ['a' => 1]);
        $ops->failMulti = true;
        $ops->calls = [];

        self::assertSame(1, $adapter->getItem('a')->get());
        self::assertSame(['mget', 'get'], $ops->calls);
    }

    public function test_commit_uses_one_mset_per_lifetime(): void
    {
        $ops = new RecordingKvOps();
        $adapter = new EphpmKvAdapter('app', 0, $ops);

        self::assertTrue($this->saveAll($adapter, $ops, ['a' => 'one', 'b' => 'two'], 120));
        self::assertSame(['mset'], $ops->calls);
        self::assertSame('two', $adapter->getItem('b')->get());

        // Every key got the batch TTL.
        $ttls = [];
        foreach (['a', 'b'] as $key) {
            $ttls[] = $ops->inner->ttl($this->namespacedId($adapter, $key));
        }
        self::assertSame([120, 120], $ttls);
    }

    public function test_commit_without_ttl_stores_without_expiry(): void
    {
        $ops = new RecordingKvOps();
        $adapter = new EphpmKvAdapter('', 0, $ops);
        $this->saveAll($adapter, $ops, ['a' => 'one']);
        self::assertSame(-1, $ops->inner->ttl($this->namespacedId($adapter, 'a')));
    }

    public function test_failed_mset_falls_back_to_per_key_set(): void
    {
        $ops = new RecordingKvOps();
        $ops->failMulti = true;
        $adapter = new EphpmKvAdapter('', 0, $ops);

        self::assertTrue($this->saveAll($adapter, $ops, ['a' => 'one', 'b' => 'two']));
        self::assertSame(['mset', 'set', 'set'], $ops->calls);
        self::assertNotNull($ops->inner->get($this->namespacedId($adapter, 'a')));
        self::assertNotNull($ops->inner->get($this->namespacedId($adapter, 'b')));
    }

    public function test_save_falls_back_to_set_without_mset(): void
    {
        $ops = new RecordingKvOps([]);
        $adapter = new EphpmKvAdapter('', 0, $ops);
        self::assertTrue($this->saveAll($adapter, $ops, ['a' => 'one', 'b' => 'two']));
        self::assertSame(['set', 'set'], $ops->calls);
    }

    /**
     * The id AbstractAdapter stores `$key` under (namespace prefix applied).
     */
    private function namespacedId(EphpmKvAdapter $adapter, string $key): string
    {
        $getId = \Closure::bind(
            fn (string $k): string => $this->getId($k),
            $adapter,
            \Symfony\Component\Cache\Adapter\AbstractAdapter::class,
        );
        return $getId($key);
    }
}
