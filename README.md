# ephpm/cache-symfony

[Symfony Cache](https://symfony.com/doc/current/components/cache.html) adapter
that stores items in [ePHPm](https://ephpm.dev)'s in-process KV store via the
`ephpm_kv_*` SAPI functions. Same PSR-6 / Symfony Contracts API your app
already speaks, zero socket round-trips, zero RESP parsing — every cache hit
is a direct C call into the Rust DashMap embedded next to PHP.

**Compatibility:** `php: ^8.2`, `symfony/cache: ^6.4 || ^7.0`. Tests run on
PHP 8.2 / 8.3 / 8.4 in CI.

```yaml
# config/services.yaml — register the adapter as your framework cache pool.
services:
    Ephpm\Cache\Symfony\EphpmKvAdapter:
        arguments:
            $namespace: 'app'
            $defaultLifetime: 0

# config/packages/cache.yaml
framework:
    cache:
        app: Ephpm\Cache\Symfony\EphpmKvAdapter
```

```php
// Anywhere in your app — controllers, services, console commands.
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

public function __construct(private CacheInterface $cache) {}

public function show(int $id): array
{
    return $this->cache->get("post.{$id}", function (ItemInterface $item): array {
        $item->expiresAfter(300);
        return $this->loadPostFromDb($id);
    });
}
```

No Redis daemon, no Memcached, no APCu shared memory — the cache lives in
the same OS process as your PHP code. Restart-loses-state is the cost; a
single C function call per `get`/`set` is the upside.

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [End-to-end: a fresh PHP project](#end-to-end-a-fresh-php-project)
- [Symfony framework integration](#symfony-framework-integration)
- [Direct / framework-less use](#direct--framework-less-use)
- [PSR-6 vs Symfony Contracts API](#psr-6-vs-symfony-contracts-api)
- [Verifying the connection is live](#verifying-the-connection-is-live)
- [Supported behavior](#supported-behavior)
- [Limitations](#limitations)
- [Testing without ePHPm](#testing-without-ephpm)
- [Troubleshooting](#troubleshooting)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **`symfony/cache` ^6.4 || ^7.0** (Composer pulls this in automatically)
- **The ePHPm runtime** — the global `ephpm_kv_*` SAPI functions are
  registered by ePHPm's embedded PHP. If you're running your code under
  PHP-FPM, Apache mod_php, or the stock PHP CLI, those functions don't
  exist and `SapiKvOps::__construct()` throws on instantiation. For
  development without ePHPm running, see
  [Testing without ePHPm](#testing-without-ephpm).

You can confirm the SAPI is present from any PHP file with:

```php
var_dump(function_exists('ephpm_kv_get'));   // expect bool(true)
```

If you get `false`, you're not running inside ePHPm and the adapter will
refuse to construct.

---

## Install

```bash
composer require ephpm/cache-symfony
```

That's it. Composer pulls in `symfony/cache` if you don't already have it.

If you're starting a brand-new project from scratch:

```bash
mkdir my-app && cd my-app
composer init --no-interaction --name=acme/my-app --require=php:^8.2
composer require ephpm/cache-symfony
```

---

## End-to-end: a fresh PHP project

The shortest possible setup — a single `index.php` that constructs the
adapter directly, no framework involved. You can drop this into any
ePHPm document root.

### 1. Project layout

```
my-app/
├── composer.json
├── ephpm.toml
├── vendor/
└── public/
    └── index.php
```

### 2. `composer.json`

```json
{
    "name": "acme/my-app",
    "require": {
        "php": "^8.2",
        "ephpm/cache-symfony": "^0.1"
    },
    "autoload": {
        "files": ["vendor/autoload.php"]
    }
}
```

```bash
composer install
```

### 3. `public/index.php`

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Ephpm\Cache\Symfony\EphpmKvAdapter;

// One-time setup. In a real app this lives in your DI container — see the
// framework integration section below.
$cache = new EphpmKvAdapter(namespace: 'demo', defaultLifetime: 60);

// Symfony Contracts API (the nicer one).
$visits = $cache->get('visits', function ($item) use ($cache) {
    $item->expiresAfter(3600);
    return 0;
});

// Increment + write back. (You could also keep the counter in the KV
// store directly via Ephpm\Cache\Symfony\SapiKvOps::incrBy — see "How it
// works" below.)
$item = $cache->getItem('visits');
$item->set(($item->get() ?? 0) + 1);
$cache->save($item);

header('Content-Type: text/plain');
echo "This page has been served {$item->get()} times.\n";
```

### 4. `ephpm.toml`

Point ePHPm at the docroot:

```toml
[server]
listen = "127.0.0.1:8080"
document_root = "./public"
```

### 5. Run it

```bash
ephpm serve --config ephpm.toml
```

Then `curl http://127.0.0.1:8080/` and watch the counter go up. Every
hit is a single in-process function call into the KV store; no network,
no socket, no RESP parsing.

---

## Symfony framework integration

The adapter implements both PSR-6 (`CacheItemPoolInterface`) and Symfony's
`CacheInterface`/`AdapterInterface`, so it slots in anywhere
`framework.cache.app` (or any other pool) can go.

### `config/services.yaml`

```yaml
services:
    # Defaults — autowire/autoconfigure as usual.
    _defaults:
        autowire: true
        autoconfigure: true

    # The adapter itself. Inject any namespace/lifetime you want, or
    # leave them at the defaults.
    Ephpm\Cache\Symfony\EphpmKvAdapter:
        arguments:
            $namespace: 'app'
            $defaultLifetime: 0

    # Optional: alias the standard Symfony interfaces to this adapter so
    # type-hint-based injection picks it up automatically.
    Symfony\Component\Cache\Adapter\AdapterInterface:
        alias: Ephpm\Cache\Symfony\EphpmKvAdapter

    Psr\Cache\CacheItemPoolInterface:
        alias: Ephpm\Cache\Symfony\EphpmKvAdapter

    Symfony\Contracts\Cache\CacheInterface:
        alias: Ephpm\Cache\Symfony\EphpmKvAdapter
```

### `config/packages/cache.yaml`

```yaml
framework:
    cache:
        # Make the framework's app pool resolve to our adapter.
        app: Ephpm\Cache\Symfony\EphpmKvAdapter

        # Optional: declare it as a "system cache" replacement too.
        # system: Ephpm\Cache\Symfony\EphpmKvAdapter

        # Optional: define additional pools that share the same backend
        # but live in a different namespace. (Each pool's AbstractAdapter
        # namespace keeps keys separate.)
        pools:
            cache.posts:
                adapter: Ephpm\Cache\Symfony\EphpmKvAdapter
            cache.sessions:
                adapter: Ephpm\Cache\Symfony\EphpmKvAdapter
```

### Using `cache.adapter.psr6` / `cache.adapter.symfony`

Symfony's built-in `cache.adapter.psr6` and `cache.adapter.symfony`
service IDs are abstract decorators that wrap whichever real adapter
you give them. Because `EphpmKvAdapter` already implements both
contracts, you can use it directly in any place those IDs are accepted:

```yaml
framework:
    cache:
        pools:
            my_psr6_pool:
                adapter: Ephpm\Cache\Symfony\EphpmKvAdapter
```

Anything resolved through `Symfony\Component\Cache` — `Cache::get`,
`Cache::delete`, PSR-6 `CacheItemPoolInterface` consumers, PSR-16
`Psr\SimpleCache\CacheInterface` (via Symfony's `Psr16Cache` wrapper) —
now talks to the SAPI.

---

## Direct / framework-less use

If you're not on Symfony, instantiate the adapter directly:

```php
use Ephpm\Cache\Symfony\EphpmKvAdapter;

$cache = new EphpmKvAdapter('app', 0);

// PSR-6 style.
$item = $cache->getItem('user:42');
if (!$item->isHit()) {
    $item->set($loadUser(42));
    $item->expiresAfter(3600);
    $cache->save($item);
}
$user = $item->get();

// Symfony Contracts style — recompute-on-miss in one call.
$user = $cache->get('user:42', function ($item) {
    $item->expiresAfter(3600);
    return $loadUser(42);
});
```

Anything that takes a `Psr\Cache\CacheItemPoolInterface`,
`Symfony\Component\Cache\Adapter\AdapterInterface`, or
`Symfony\Contracts\Cache\CacheInterface` keeps working — it's a plain
`AbstractAdapter` subclass with a custom backend.

---

## PSR-6 vs Symfony Contracts API

Both work; pick whichever fits your call site.

| API                                    | Method shape                        | When to reach for it                              |
| -------------------------------------- | ----------------------------------- | ------------------------------------------------- |
| **PSR-6** (`CacheItemPoolInterface`)   | `getItem`, `save`, `deleteItem`     | Interop with libraries that demand PSR-6.         |
| **Symfony Contracts** (`CacheInterface`) | `get($key, $callback)` — recompute-on-miss | Most application code; the callback signature avoids the `isHit`/`save` dance. |

The Contracts API also gives you stampede protection via
`Symfony\Component\Cache\LockRegistry` automatically — no extra wiring
needed.

---

## Verifying the connection is live

A three-line health check you can hit from any PHP entry point to confirm
the SAPI surface is wired and the cache round-trips:

```php
$item = $cache->getItem('__healthcheck')->set('1')->expiresAfter(5);
$cache->save($item);
assert($cache->getItem('__healthcheck')->get() === '1');
```

If this passes you've confirmed:

- ePHPm's SAPI is loaded (`ephpm_kv_*` functions exist)
- The KV store is up
- TTL parsing works
- The marshaller round-trips your value bytes
- Symfony Cache's namespace plumbing is wired

---

## Supported behavior

| Operation                                        | Status / notes                                                          |
| ------------------------------------------------ | ----------------------------------------------------------------------- |
| `getItem` / `getItems` / `hasItem`               | Supported.                                                              |
| `save` / `saveDeferred` / `commit`               | Supported. TTL respects `$item->expiresAfter()` / `expiresAt()`, falling back to the adapter's `defaultLifetime`. |
| `deleteItem` / `deleteItems`                     | Supported.                                                              |
| `get($key, $callback)` (Contracts)               | Supported (including stampede protection via `LockRegistry`).           |
| `clear()`                                        | **Returns `false`** — see Limitations.                                  |
| Tagging (`TagAwareAdapterInterface`)             | Not implemented.                                                        |
| Marshalling                                      | `Symfony\Component\Cache\Marshaller\DefaultMarshaller` (igbinary when available, native serialize otherwise). Inject your own `MarshallerInterface` via the constructor to override. |

---

## Limitations

**`clear()` is a no-op (returns `false`).**
The ePHPm KV SAPI doesn't expose key enumeration — there's no `SCAN`, no
`KEYS`, no namespace-prefix iteration. The recommended invalidation
pattern is **namespace versioning**: bump the namespace string when you
need to wipe everything.

```php
// Old keys still exist in the KV store but are no longer reachable
// through this pool. They expire naturally via TTL or get evicted.
$cache = new EphpmKvAdapter('app.v2', 0);  // was 'app.v1'
```

If you genuinely need bulk eviction, layer in a TTL on every save
(e.g. set `$defaultLifetime` to a value short enough that the worst-case
"stale entry" window is tolerable for your app).

**No tagging support.**
We do not implement `TagAwareAdapterInterface`. If you need tag-based
invalidation, wrap this adapter with Symfony's `TagAwareAdapter`, which
maintains its own tag-to-key index in a second pool. The tag pool can
also be an `EphpmKvAdapter` instance.

**No `SCAN`, so key enumeration tools won't work.**
Cache stampede protection via `LockRegistry` works fine because it only
needs read/write of single, well-known lock keys. But anything that
wants to walk the keyspace (custom invalidators, debug dumps,
`bin/console cache:pool:list` introspection beyond the pool name) will
come up empty.

---

## Testing without ePHPm

The adapter takes an optional `KvOpsInterface`, so you can swap in a
fake backend that runs anywhere — including standard PHPUnit suites on
plain `php-cli`:

```php
use Ephpm\Cache\Symfony\EphpmKvAdapter;
use Ephpm\Cache\Symfony\InMemoryKvOps;

$cache = new EphpmKvAdapter('test', 0, new InMemoryKvOps());
$item = $cache->getItem('foo')->set('bar');
$cache->save($item);
assert($cache->getItem('foo')->get() === 'bar');
```

`InMemoryKvOps` is for tests only — values live in PHP arrays, there is
no eviction policy, no memory limit, and TTL is best-effort lazy
expiry. Don't use it in production.

---

## Troubleshooting

### `RuntimeException: ephpm KV SAPI functions are not available`

You're not running under the ePHPm runtime. Either run your code
through the `ephpm` binary (production), or pass an `InMemoryKvOps` as
the third arg of `EphpmKvAdapter` (tests).

### Cache items are mysteriously gone

You're calling `clear()` and expecting it to nuke the pool. It doesn't —
`clear()` returns `false` because the SAPI has no `SCAN`. Switch to
**namespace versioning**: change the namespace string passed to
`EphpmKvAdapter::__construct()` (e.g. `'app.v1'` → `'app.v2'`) and the
old keys become unreachable through the new pool. They'll expire on
their own via TTL.

### Cached values come back as null after restart

By design. ePHPm's KV is in-process — when the `ephpm` binary stops, the
DashMap goes with it. TTLs are honored while the process lives, but
restart-loses-state is the trade for the zero-RTT, zero-serialization
hot path. If you need durability across restarts, layer a real Redis
behind a Symfony `ChainAdapter` and put `EphpmKvAdapter` in front. See
[ephpm.dev/architecture/kv-store/](https://ephpm.dev/architecture/kv-store/)
for the full design rationale.

---

## How it works

ePHPm runs PHP inside the same OS process as the KV store via the embed
SAPI. The store itself is a Rust [`DashMap`](https://docs.rs/dashmap/)
plus TTL management. ePHPm registers a small set of host functions
(`ephpm_kv_get`, `ephpm_kv_set`, `ephpm_kv_incr_by`, `ephpm_kv_expire`,
`ephpm_kv_ttl`, `ephpm_kv_pttl`, `ephpm_kv_del`, `ephpm_kv_exists`) into
PHP's global function table. Calling one is a direct C function call
into Rust — no socket, no protocol parser, no value serialization beyond
what userland code already does.

This package wraps those functions in a `Symfony\Component\Cache\Adapter\AbstractAdapter`
subclass, so existing Symfony-using code (frameworks, libraries, your
own services) doesn't need to know anything has changed. Same
`CacheInterface::get($key, $callback)` and PSR-6 `getItem`/`save` API,
different transport underneath.

See [ephpm.dev/architecture/kv-store/](https://ephpm.dev/architecture/kv-store/)
for the architecture and [ephpm.dev/guides/kv-from-php/](https://ephpm.dev/guides/kv-from-php/)
for the underlying SAPI surface.

---

## License

MIT — see [LICENSE](LICENSE).
