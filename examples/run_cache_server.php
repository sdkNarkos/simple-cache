<?php
declare(strict_types=1);

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);

set_time_limit(0);

include_once(__DIR__ . '/../vendor/autoload.php');

use sdkNarkos\SimpleCache\Server\CacheServer;

try {
    // Define cache options, only the authKeys array is required, with at least one authKey
    $cacheConfig = array(
        'authKeys' => array(
            'exampleKeyZRDfgirt87ftztrdVgZ73j'
        ),
        'verbose' => true,
        /*'logger' => function (string $level, string $message) {
            file_put_contents(__DIR__ . '/server.log',
                '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message . PHP_EOL,
                FILE_APPEND
            );
        }*/
    );
    // Instantiates and runs the cache server
    $KosCacheServer = new CacheServer($cacheConfig);
    $KosCacheServer->run();
} catch (\Exception $ex) {
    echo $ex->getMessage();
}

?>