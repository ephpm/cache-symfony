<?php

declare(strict_types=1);

/*
 * SapiKvOps calls the global ephpm_kv_* functions that only exist inside
 * the ePHPm runtime. To exercise it off-runtime we install controllable
 * stubs into the global namespace (guarded by function_exists so a real
 * runtime, if ever present, wins). A static holder lets each test steer
 * what the stubbed SAPI returns — notably ephpm_kv_incr_by returning
 * `false` to model "stored value is not an integer".
 */

namespace Ephpm\Cache\Symfony\Tests\Fixtures {
    final class FakeSapiState
    {
        /** @var array<string, string> */
        public static array $store = [];

        /** When true, ephpm_kv_incr_by() returns false (non-integer value). */
        public static bool $incrReturnsFalse = false;

        public static function reset(): void
        {
            self::$store = [];
            self::$incrReturnsFalse = false;
        }
    }
}

namespace {
    use Ephpm\Cache\Symfony\Tests\Fixtures\FakeSapiState;

    if (!\function_exists('ephpm_kv_get')) {
        function ephpm_kv_get(string $key): ?string
        {
            return FakeSapiState::$store[$key] ?? null;
        }

        function ephpm_kv_set(string $key, string $value, int $ttlSeconds = 0): bool
        {
            FakeSapiState::$store[$key] = $value;

            return true;
        }

        function ephpm_kv_del(string $key): int
        {
            if (!\array_key_exists($key, FakeSapiState::$store)) {
                return 0;
            }
            unset(FakeSapiState::$store[$key]);

            return 1;
        }

        function ephpm_kv_exists(string $key): bool
        {
            return \array_key_exists($key, FakeSapiState::$store);
        }

        function ephpm_kv_incr_by(string $key, int $delta): int|false
        {
            if (FakeSapiState::$incrReturnsFalse) {
                return false;
            }
            $current = (int) (FakeSapiState::$store[$key] ?? 0);
            $next = $current + $delta;
            FakeSapiState::$store[$key] = (string) $next;

            return $next;
        }

        function ephpm_kv_expire(string $key, int $ttlSeconds): bool
        {
            return \array_key_exists($key, FakeSapiState::$store);
        }

        function ephpm_kv_ttl(string $key): int
        {
            return \array_key_exists($key, FakeSapiState::$store) ? -1 : -2;
        }

        function ephpm_kv_pttl(string $key): int
        {
            return \array_key_exists($key, FakeSapiState::$store) ? -1 : -2;
        }

        function ephpm_kv_flush_all(): bool
        {
            FakeSapiState::$store = [];

            return true;
        }
    }
}

namespace Ephpm\Cache\Symfony\Tests {
    use Ephpm\Cache\Symfony\SapiKvOps;
    use Ephpm\Cache\Symfony\Tests\Fixtures\FakeSapiState;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    #[CoversClass(SapiKvOps::class)]
    final class SapiKvOpsTest extends TestCase
    {
        protected function setUp(): void
        {
            FakeSapiState::reset();
        }

        public function test_incr_by_returns_new_value_on_success(): void
        {
            $ops = new SapiKvOps();
            self::assertSame(5, $ops->incrBy('counter', 5));
            self::assertSame(7, $ops->incrBy('counter', 2));
        }

        public function test_incr_by_throws_when_sapi_reports_non_integer(): void
        {
            // Regression: incrBy() used to do `(int) ephpm_kv_incr_by(...)`,
            // and `(int) false === 0`, so a non-integer stored value was
            // silently reported as the counter value 0. It must throw
            // (KvOpsInterface::incrBy contract) instead.
            FakeSapiState::$incrReturnsFalse = true;
            $ops = new SapiKvOps();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('stored value is not an integer');
            $ops->incrBy('label', 1);
        }
    }
}
