<?php
declare(strict_types=1);

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);

include_once(__DIR__ . '/../vendor/autoload.php');

use sdkNarkos\SimpleCache\Client\CacheClient;

try {
    // Define cache options
    $cacheConfig = array(
        'authKey' => 'exampleKeyZRDfgirt87ftztrdVgZ73j',
        /*'logger' => function (string $level, string $message) {
            file_put_contents(__DIR__ . '/client.log',
                '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message . PHP_EOL,
                FILE_APPEND
            );
        }*/
    );
    // Instantiates the cache client
    $cacheClient = new CacheClient($cacheConfig);
    // Stores a value in the cache server
    $cacheClient->set('test_key', str_repeat('a1b2', 10000000) . 'END1', 10);
    // Get a value from the cache server
    $storedValue = $cacheClient->get('test_key');
    echo 'Returned value length: (' . strlen($storedValue) . ')' . PHP_EOL;
    echo $cacheClient->ping() . PHP_EOL;
    $cacheClient->listSet('members', array('bill', 'kankers'));
    $cacheClient->listAddFirst('members', 'bill - 1');
    $cacheClient->listAddFirst('members', array('bill - 3', 'bill - 2'));
    $cacheClient->listAddLast('members', 'kankers - 1');
    $cacheClient->listAddLast('members', array('kankers - 2', 'kankers - 3'));
    $members = $cacheClient->listGet('members');
    var_dump($members);
} catch (\Exception $ex) {
    echo 'Error: ' . $ex->getMessage() . PHP_EOL;
}

?>
