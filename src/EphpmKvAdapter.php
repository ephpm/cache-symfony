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
 * On ePHPm v0.13+ multi-item fetches and saves use one `ephpm_kv_mget` /
 * `ephpm_kv_mset` call per batch (feature-detected; older runtimes and
 * backends without {@see ExtendedKvOpsInterface} keep the per-key loop).
 *
 * Limitations:
 *  - `clear()` only works on an un-namespaced adapter. With a namespace,
 *    it returns `false`: there is no reliable key enumeration (v0.13's
 *    `ephpm_kv_scan` sees only the local node's large values in a
 *    cluster), so we can't delete by prefix without nuking other
 *    namespaces or missing keys — the
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
        $ids = \array_values($ids);
        $raws = null;
        // ePHPm v0.13+: one ephpm_kv_mget call for the whole batch. A false
        // (the call failed as a whole) falls back to the per-key reads.
        if ($ids !== [] && $this->ops instanceof ExtendedKvOpsInterface && $this->ops->supports('mget')) {
            $fetched = $this->ops->mget($ids);
            $raws = \is_array($fetched) ? $fetched : null;
        }

        foreach ($ids as $i => $id) {
            $raw = $raws !== null ? ($raws[$i] ?? null) : $this->ops->get($id);
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

        // ePHPm v0.13+: one ephpm_kv_mset call — AbstractAdapter groups
        // doSave() calls by lifetime, so one TTL for the batch is exact.
        // mset reports only all-or-nothing (a refusal stops it part-way), so
        // on false fall through to the per-key loop: re-writing the keys
        // that did land is harmless and yields the exact list of failed ids.
        if ($serialized !== []
            && $this->ops instanceof ExtendedKvOpsInterface
            && $this->ops->supports('mset')
            && $this->ops->mset($serialized, $lifetime)
        ) {
            return $failed ?: true;
        }

        foreach ($serialized as $id => $value) {
            // set() returns false only on a genuine store failure (OOM under
            // noeviction). AbstractAdapter::doSave's contract is to return the
            // list of ids that could not be persisted, so a false here must be
            // reported — otherwise a dropped write looks like a successful save.
            if (!$this->ops->set($id, $value, $lifetime)) {
                $failed[] = $id;
            }
        }
        return $failed ?: true;
    }

    protected function doClear(string $namespace): bool
    {
        // A namespace-scoped clear needs prefix enumeration. Before ePHPm
        // v0.13 there is none; v0.13's ephpm_kv_scan exists but in a
        // cluster lists only THIS node's keyspace (string values above
        // [cluster.kv] small_key_threshold live only on their replica
        // nodes), so a scan-and-delete would silently leave entries behind
        // — and a clear that reports success while stale values survive is
        // worse than an honest false. So it stays unsupported. Match
        // RedisAdapter: when the adapter has no namespace, fall back to
        // a global flush (here: ephpm_kv_flush_all() via ops->flush());
        // otherwise return false and rely on namespace versioning.
        if ($namespace !== '') {
            return false;
        }
        return $this->ops->flush();
    }
}
