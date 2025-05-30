<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache;

class CacheClientQuick {
    private static function createClient(array $config): CacheClient {
        return new CacheClient($config);
    }

    public static function get(array $config, string $key): mixed {
        $client = self::createClient($config);
        return $client->get($key);
    }

    public static function set(array $config, string $key, string $value, int $lifetime = 0): mixed {
        $client = self::createClient($config);
        return $client->set($key, $value, $lifetime);
    }

    public static function remove(array $config, string $key): mixed {
        $client = self::createClient($config);
        return $client->remove($key);
    }

    public static function exists(array $config, string $key): bool {
        $client = self::createClient($config);
        return $client->exists($key);
    }

    public static function getRem(array $config, string $key): mixed {
        $client = self::createClient($config);
        return $client->getRem($key);
    }

    public static function getAllKeys(array $config): array {
        $client = self::createClient($config);
        return $client->getAllKeys();
    }
}