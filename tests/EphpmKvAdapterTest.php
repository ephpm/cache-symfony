<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony\Tests;

use Ephpm\Cache\Symfony\EphpmKvAdapter;
use Ephpm\Cache\Symfony\InMemoryKvOps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EphpmKvAdapter::class)]
final class EphpmKvAdapterTest extends TestCase
{
    public function test_string_round_trips_through_get_item_and_save(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());

        $item = $adapter->getItem('greeting');
        self::assertFalse($item->isHit());

        $item->set('hello');
        self::assertTrue($adapter->save($item));

        $reloaded = $adapter->getItem('greeting');
        self::assertTrue($reloaded->isHit());
        self::assertSame('hello', $reloaded->get());
    }

    public function test_array_object_int_float_null_all_round_trip(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());

        $cases = [
            'arr'   => ['a', 'b', 'c'],
            'assoc' => ['k' => 'v', 'n' => 1],
            'obj'   => (object) ['x' => 1, 'y' => 'two'],
            'int'   => 42,
            'float' => 3.14,
            'null'  => null,
            'bool'  => true,
        ];

        foreach ($cases as $key => $value) {
            $item = $adapter->getItem($key);
            $item->set($value);
            self::assertTrue($adapter->save($item), "save({$key})");
        }

        foreach ($cases as $key => $value) {
            $reloaded = $adapter->getItem($key);
            self::assertTrue($reloaded->isHit(), "hit({$key})");
            self::assertEquals($value, $reloaded->get(), "value({$key})");
        }
    }

    public function test_expired_item_is_no_longer_present(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());
        $item = $adapter->getItem('short');
        $item->set('soon-gone')->expiresAfter(1);
        $adapter->save($item);

        // Just inside the window — still a hit.
        self::assertTrue($adapter->getItem('short')->isHit());

        // Sleep just past the deadline.
        \usleep(1_100_000);

        $missed = $adapter->getItem('short');
        self::assertFalse($missed->isHit());
        self::assertNull($missed->get());
    }

    public function test_multi_fetch_returns_all_hit_items_omits_misses(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());

        $a = $adapter->getItem('a');
        $a->set(1);
        $adapter->save($a);

        $c = $adapter->getItem('c');
        $c->set(3);
        $adapter->save($c);

        $items = \iterator_to_array($adapter->getItems(['a', 'b', 'c']));

        self::assertCount(3, $items);
        self::assertTrue($items['a']->isHit());
        self::assertSame(1, $items['a']->get());
        self::assertFalse($items['b']->isHit());
        self::assertNull($items['b']->get());
        self::assertTrue($items['c']->isHit());
        self::assertSame(3, $items['c']->get());
    }

    public function test_delete_removes_the_item(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());
        $item = $adapter->getItem('doomed');
        $item->set('x');
        $adapter->save($item);

        self::assertTrue($adapter->hasItem('doomed'));
        self::assertTrue($adapter->deleteItem('doomed'));
        self::assertFalse($adapter->hasItem('doomed'));
    }

    public function test_clear_flushes_when_adapter_has_no_namespace(): void
    {
        $ops = new InMemoryKvOps();
        $adapter = new EphpmKvAdapter('', 0, $ops);

        $a = $adapter->getItem('a');
        $a->set('alpha');
        $adapter->save($a);
        $b = $adapter->getItem('b');
        $b->set('beta');
        $adapter->save($b);

        // Un-namespaced adapter falls back to ephpm_kv_flush_all() — the
        // same contract Symfony's RedisAdapter uses when no namespace is
        // configured (FLUSHDB).
        self::assertTrue($adapter->clear());
        self::assertFalse($adapter->getItem('a')->isHit());
        self::assertFalse($adapter->getItem('b')->isHit());
    }

    public function test_clear_is_noop_when_adapter_has_namespace(): void
    {
        $ops = new InMemoryKvOps();
        $adapter = new EphpmKvAdapter('app.v1', 0, $ops);

        $item = $adapter->getItem('keep-me');
        $item->set('still-here');
        $adapter->save($item);

        // A namespaced clear would need SCAN to avoid nuking other
        // adapters' keys. Without SCAN we refuse: callers bump the
        // namespace ('app.v1' -> 'app.v2') instead.
        self::assertFalse($adapter->clear());
        self::assertTrue($adapter->getItem('keep-me')->isHit());
    }

    public function test_clear_with_namespace_does_not_disturb_other_namespaces(): void
    {
        $ops = new InMemoryKvOps();
        $appA = new EphpmKvAdapter('app.a', 0, $ops);
        $appB = new EphpmKvAdapter('app.b', 0, $ops);

        $itemA = $appA->getItem('x');
        $itemA->set('from-a');
        $appA->save($itemA);
        $itemB = $appB->getItem('x');
        $itemB->set('from-b');
        $appB->save($itemB);

        self::assertFalse($appA->clear());
        // Both still present — the namespaced clear was a no-op, so
        // neither namespace lost data.
        self::assertSame('from-a', $appA->getItem('x')->get());
        self::assertSame('from-b', $appB->getItem('x')->get());
    }

    public function test_default_lifetime_applies_when_item_lifetime_not_set(): void
    {
        $ops = new InMemoryKvOps();
        $adapter = new EphpmKvAdapter('', 1, $ops);

        $item = $adapter->getItem('with-default-ttl');
        $item->set('boom');
        $adapter->save($item);

        // Right after save the item is alive.
        self::assertTrue($adapter->getItem('with-default-ttl')->isHit());

        \usleep(1_100_000);

        // The default lifetime (1s) should have elapsed.
        self::assertFalse($adapter->getItem('with-default-ttl')->isHit());
    }

    public function test_namespace_isolation_between_two_adapters_sharing_one_backend(): void
    {
        $ops = new InMemoryKvOps();

        $appA = new EphpmKvAdapter('app.a', 0, $ops);
        $appB = new EphpmKvAdapter('app.b', 0, $ops);

        $itemA = $appA->getItem('shared-key');
        $itemA->set('from-a');
        $appA->save($itemA);

        $itemB = $appB->getItem('shared-key');
        $itemB->set('from-b');
        $appB->save($itemB);

        self::assertSame('from-a', $appA->getItem('shared-key')->get());
        self::assertSame('from-b', $appB->getItem('shared-key')->get());
    }

    public function test_save_then_has_then_delete_lifecycle(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());

        self::assertFalse($adapter->hasItem('lifecycle'));

        $item = $adapter->getItem('lifecycle');
        $item->set(['stage' => 'saved']);
        self::assertTrue($adapter->save($item));
        self::assertTrue($adapter->hasItem('lifecycle'));

        self::assertTrue($adapter->deleteItem('lifecycle'));
        self::assertFalse($adapter->hasItem('lifecycle'));
    }

    public function test_save_reports_failure_when_backend_set_fails(): void
    {
        // Regression: doSave() ignored set()'s return value and always
        // returned true, so an OOM-dropped write (set() === false) looked
        // like a successful save. AbstractAdapter::save() must surface that
        // as false.
        $adapter = new EphpmKvAdapter('', 0, new FailingSetKvOps(failAll: true));

        $item = $adapter->getItem('doomed');
        $item->set('value-that-cannot-be-stored');

        self::assertFalse($adapter->save($item));
    }

    public function test_do_save_returns_list_of_failed_ids(): void
    {
        // Drive doSave() directly with several values so we can assert it
        // returns exactly the ids it failed to persist (the AbstractAdapter
        // doSave contract), not a bare true.
        $adapter = new EphpmKvAdapter('', 0, new FailingSetKvOps(failAll: true));

        $doSave = (new \ReflectionClass($adapter))->getMethod('doSave');

        $values = ['a' => 'one', 'b' => 'two', 'c' => 'three'];
        $result = $doSave->invoke($adapter, $values, 0);

        self::assertIsArray($result);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_do_save_returns_true_when_all_sets_succeed(): void
    {
        $adapter = new EphpmKvAdapter('', 0, new InMemoryKvOps());

        $doSave = (new \ReflectionClass($adapter))->getMethod('doSave');

        self::assertTrue($doSave->invoke($adapter, ['a' => 'one', 'b' => 'two'], 0));
    }

    public function test_unmarshall_failure_is_treated_as_miss(): void
    {
        // Drop a value that wasn't produced by the marshaller, so
        // unmarshall() throws (or returns false). The adapter should
        // treat that as a miss rather than propagating.
        $ops = new InMemoryKvOps();
        $adapter = new EphpmKvAdapter('', 0, $ops);

        // Reach into the underlying KV directly. The namespace prefix
        // applied by AbstractAdapter is empty here, so the on-disk key
        // matches the requested id after AbstractAdapter's hashing —
        // we use a key we never asked the adapter to compute, so we
        // ask for something the adapter built itself: write through
        // the adapter, then corrupt the stored bytes.
        $item = $adapter->getItem('corrupt');
        $item->set('legit');
        $adapter->save($item);
        self::assertTrue($adapter->getItem('corrupt')->isHit());

        // Find the key the adapter used and overwrite with garbage.
        // InMemoryKvOps stores by exact key, so iterate via the public
        // surface: the adapter writes whatever key it computed. We can
        // observe it by listing entries from the backend through a
        // reflection-free path: just overwrite every key we can reach.
        // Easier: write the raw bytes for the same key via a fresh save
        // that bypasses the marshaller — done by calling ops->set for
        // the namespaced key. AbstractAdapter::getId() is protected, so
        // we approximate by deleting the corrupt entry and re-storing
        // garbage at the same logical id via an internal probe.
        $reflection = new \ReflectionClass($adapter);
        $getId = $reflection->getMethod('getId');
        $storageKey = $getId->invoke($adapter, 'corrupt');

        $ops->set($storageKey, 'this is not a serialized payload');

        // Symfony's DefaultMarshaller routes through igbinary first when
        // the extension is loaded (it is, in CI). igbinary emits a PHP
        // warning on a bogus header before returning false, and PHPUnit's
        // failOnWarning would fail this otherwise-passing assertion.
        // Catch only the unmarshall warning so a real bug elsewhere still
        // surfaces.
        $missed = null;
        \set_error_handler(static fn () => true, \E_WARNING);
        try {
            $missed = $adapter->getItem('corrupt');
        } finally {
            \restore_error_handler();
        }
        self::assertNotNull($missed);
        self::assertFalse($missed->isHit());
    }
}
