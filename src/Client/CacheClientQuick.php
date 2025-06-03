<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Client;

class CacheClientQuick {
    private static function createClient(array $config): CacheClient {
        return new CacheClient($config);
    }

    // --- Valeurs simples ---
    public static function get(array $config, string $key): mixed {
        return self::createClient($config)->get($key);
    }

    public static function set(array $config, string $key, string $value, int $lifetime = 0): mixed {
        return self::createClient($config)->set($key, $value, $lifetime);
    }

    public static function remove(array $config, string $key): mixed {
        return self::createClient($config)->remove($key);
    }

    public static function exists(array $config, string $key): bool {
        return self::createClient($config)->exists($key);
    }

    public static function getRem(array $config, string $key): mixed {
        return self::createClient($config)->getRem($key);
    }

    public static function expire(array $config, string $key, int $ttl): mixed {
        return self::createClient($config)->expire($key, $ttl);
    }

    public static function getAllKeys(array $config): array {
        return self::createClient($config)->getAllKeys();
    }

    // --- Listes ---
    public static function listExists(array $config, string $key): bool {
        return self::createClient($config)->listExists($key);
    }

    public static function listRemove(array $config, string $key): mixed {
        return self::createClient($config)->listRemove($key);
    }

    public static function listAddFirst(array $config, string $key, string $value): mixed {
        return self::createClient($config)->listAddFirst($key, $value);
    }

    public static function listAddLast(array $config, string $key, string $value): mixed {
        return self::createClient($config)->listAddLast($key, $value);
    }

    public static function listGetFirst(array $config, string $key): mixed {
        return self::createClient($config)->listGetFirst($key);
    }

    public static function listGetLast(array $config, string $key): mixed {
        return self::createClient($config)->listGetLast($key);
    }

    public static function listGetRemFirst(array $config, string $key): mixed {
        return self::createClient($config)->listGetRemFirst($key);
    }

    public static function listGetRemLast(array $config, string $key): mixed {
        return self::createClient($config)->listGetRemLast($key);
    }

    public static function listExpire(array $config, string $key, int $ttl): mixed {
        return self::createClient($config)->listExpire($key, $ttl);
    }

    public static function listSet(array $config, string $key, array $values): mixed {
        return self::createClient($config)->listSet($key, $values);
    }

    public static function listGet(array $config, string $key): mixed {
        return self::createClient($config)->listGet($key);
    }

    // Divers
    public static function ping(array $config): string {
        return self::createClient($config)->ping();
    }

    public static function stats(array $config): array {
        return self::createClient($config)->stats();
    }
}