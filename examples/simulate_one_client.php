<?php
declare(strict_types=1);

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);

include_once(__DIR__ . '/../vendor/autoload.php');

use sdkNarkos\SimpleCache\Client\CacheClient;

$clientId = $argv[1] ?? rand(1000, 9999);

try {
    $cacheConfig = array(
        'authKey' => 'exampleKeyZRDfgirt87ftztrdVgZ73j'
    );
    $client = new CacheClient($cacheConfig);

    $key = 'key_' . $clientId . '_' . uniqid();
    $value = "value_" . rand(1, 10000);

    $client->set($key, $value);
    $response = $client->get($key);

    if ($response !== $value) {
        echo "Client $clientId: ERREUR - attendu=$value reçu=$response\n";
    } else {
       // echo "Client $clientId: OK\n";
    }
} catch (Exception $e) {
    echo "Client $clientId: Exception - " . $e->getMessage() . "\n";
}