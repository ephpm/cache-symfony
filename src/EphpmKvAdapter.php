<?php

declare(strict_types=1);

namespace Ephpm\Cache\Symfony;

use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * PSR-6 / Symfony Cache adapter that stores items in ePHPm's in-process
 * KV store via the `ephpm_kv_*` SAPI surface.
 *
 * Routes the abstract `doFetch`/`doHave`/`doDelete`/`doSave`/`doClear`
 * hooks defined by {@see AbstractAdapter} to a {@see KvOpsInterface}
 * backend. The default backend is {@see SapiKvOps} (calls into the
 * runtime); tests can pass {@see InMemoryKvOps} to run anywhere.
 *
 * Marshalling uses Symfony's {@see DefaultMarshaller} so we get the same
 * value semantics (igbinary when available, native serialize otherwise,
 * `false`-on-unmarshall-failure handling) as `RedisAdapter`.
 *
 * Limitations:
 *  - `clear()` always returns false. The SAPI does not expose key
 *    enumeration (no SCAN), so we cannot delete by namespace prefix.
 *    Use a versioned namespace (`new EphpmKvAdapter('app.v2')`) when you
 *    need to invalidate everything at once.
 *  - No tagging support (no `TagAwareAdapterInterface`).
 *  - `LockRegistry` cache-stampede protection works because it relies
 *    on read/write of single keys, but anything that needs key
 *    enumeration won't.
 */
final class EphpmKvAdapter extends AbstractAdapter
{
    private KvOpsInterface $ops;
    private MarshallerInterface $marshaller;

    public function __construct(
        string $namespace = '',
        int $defaultLifetime = 0,
        ?KvOpsInterface $ops = null,
        ?MarshallerInterface $marshaller = null,
    ) {
        parent::__construct($namespace, $defaultLifetime);
        $this->ops = $ops ?? new SapiKvOps();
        $this->marshaller = $marshaller ?? new DefaultMarshaller();
    }

    protected function doFetch(array $ids): iterable
    {
        foreach ($ids as $id) {
            $raw = $this->ops->get($id);
            if ($raw === null) {
                continue;
            }
            try {
                $value = $this->marshaller->unmarshall($raw);
            } catch (\Throwable) {
                // Mirrors RedisAdapter: a corrupt/incompatible payload is
                // treated as a miss rather than propagating up the stack.
                continue;
            }
            yield $id => $value;
        }
    }

    protected function doHave(string $id): bool
    {
        return $this->ops->exists($id);
    }

    protected function doDelete(array $ids): bool
    {
        foreach ($ids as $id) {
            $this->ops->del($id);
        }
        return true;
    }

    protected function doSave(array $values, int $lifetime): array|bool
    {
        $failed = [];
        $serialized = $this->marshaller->marshall($values, $failed);
        foreach ($serialized as $id => $value) {
            $this->ops->set($id, $value, $lifetime);
        }
        return $failed ?: true;
    }

    protected function doClear(string $namespace): bool
    {
        // The SAPI doesn't expose key enumeration (no SCAN), so we can't
        // delete by prefix. Return false so callers know clear() was a
        // no-op; the recommended invalidation pattern is namespace
        // versioning. See README "Limitations".
        return false;
    }
}
