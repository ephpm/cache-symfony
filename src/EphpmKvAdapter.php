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
 *  - `clear()` only works on an un-namespaced adapter. With a namespace,
 *    it returns `false`: the SAPI exposes no key enumeration (no SCAN),
 *    so we can't delete by prefix without nuking other namespaces — the
 *    safer choice is to leave the keys and let callers use namespace
 *    versioning (`new EphpmKvAdapter('app.v2')`). An un-namespaced
 *    adapter uses `ephpm_kv_flush_all()` (ePHPm v0.1.2+), matching how
 *    `RedisAdapter` falls back to `FLUSHDB` when no namespace is set.
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
        // Without SCAN we still can't enumerate keys by prefix, so a
        // namespace-scoped clear is impossible — it would either nuke
        // unrelated adapters (flush_all) or no-op silently. Match
        // RedisAdapter: when the adapter has no namespace, fall back to
        // a global flush (here: ephpm_kv_flush_all() via ops->flush());
        // otherwise return false and rely on namespace versioning.
        if ($namespace !== '') {
            return false;
        }
        return $this->ops->flush();
    }
}
