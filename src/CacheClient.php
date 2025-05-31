<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache;

class CacheClient {
    private readonly string $authKey;
    private readonly string $protocol;
    private readonly string $host;
    private readonly int $port;
    private readonly float $maxReadingDelay;
    private readonly int $connectTimeout;
    private readonly int $maxReconnectAttempts;

    private $socket;
    private bool $isConnected = false;
    private int $reconnectAttempts = 0;

    public function __construct($config) {
        if(!isset($config['authKey'])) {
            throw new \Exception("No authKey found");
        }
        $this->authKey = $config['authKey'];

        $this->protocol = $config['protocol'] ?? 'tcp';
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 9999;
        $this->maxReadingDelay = $config['maxReadingDelay'] ?? 5;
        $this->connectTimeout = $config['connectTimeout'] ?? 30;
        $this->maxReconnectAttempts = $config['maxReconnectAttempts'] ?? 3;
    }

    public function __destruct() {
        $this->disconnect();
    }

    public function isConnected(): bool {
        return $this->isConnected && $this->isSocketAlive();
    }

    private function connect(): void {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_client($url, $errno, $errstr, $this->connectTimeout);
        if (!$this->socket) {
            throw new \Exception("Connection failed: $errstr ($errno)");
        }

        if (!@stream_set_blocking($this->socket, false)) {
            throw new \Exception("Unable to set the stream to non-blocking mode");
        }

        $this->isConnected = true;
    }

    private function disconnect(): void {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->isConnected = false;
    }

    private function checkConnection(): void {
        if (!$this->isConnected || !$this->isSocketAlive()) {
            $this->reconnect();
        } else {
            $this->reconnectAttempts = 0; // Reset if all good
        }
    }

    private function isSocketAlive(): bool {
        if (!is_resource($this->socket)) return false;
        $meta = stream_get_meta_data($this->socket);
        return !$meta['timed_out'] && !feof($this->socket);
    }

    private function reconnect(): void {
        $this->disconnect();

        while ($this->reconnectAttempts < $this->maxReconnectAttempts) {
            try {
                $this->connect();
                $this->reconnectAttempts = 0;
                return;
            } catch (\Exception $e) {
                $this->reconnectAttempts++;
                sleep(1 * $this->reconnectAttempts); // Delay increases linearly
            }
        }

        throw new \Exception("Failed to reconnect after {$this->maxReconnectAttempts} attempts");
    }

    private function createCallObject(string $command): \stdClass {
        return (object)[
            'authKey' => $this->authKey,
            'command' => $command
        ];
    }

    private function doCall(\stdClass $data): void {
        $jsonData = json_encode($data);
        $length = strlen($jsonData);
        $binLength = pack('N', $length);

        $written = @fwrite($this->socket, $binLength . $jsonData);
        if ($written === false) {
            $this->reconnect();
            $written = @fwrite($this->socket, $binLength . $jsonData);
            if ($written === false) {
                throw new \Exception('Error writing to the cache server after reconnect.');
            }
        }
    }

    private function readBytes(int $length): string {
        $data = '';
        $start = microtime(true);
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                if ((microtime(true) - $start) >= $this->maxReadingDelay) {
                    throw new \Exception("Timeout reading from socket.");
                }
                usleep(1000);
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function getResponse() {
        // Read 4 octets to get message length
        $lengthData = $this->readBytes(4);
        $unpacked = unpack('Nlength', $lengthData);
        $targetLength = $unpacked['length'];

        // Read the entire JSON
        $responseData = $this->readBytes($targetLength);

        $response = json_decode($responseData);

        if ($response === null) {
            throw new \Exception("Failed to decode JSON response");
        }

        if (isset($response->error)) {
            throw new \Exception($response->error);
        }

        return $response->results ?? $response;
    }

    ///////////////
    // Commands //
    /////////////

    ////////////
    // value //
    public function exists(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('exists');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function expire(string $key, float $lifetime) {
        $this->checkConnection();
        
        $data = $this->createCallObject('expire');
        $data->key = $key;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function get(string $key) {
        $this->checkConnection();

        $data = $this->createCallObject('get');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function getAllKeys() {
        $this->checkConnection();
        
        $data = $this->createCallObject('getAllKeys');

        $this->doCall($data);
        return $this->getResponse();
    }

    public function getRem(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('getRem');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function remove(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('remove');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function set(string $key, string $val, float $lifetime = 0) {
        $this->checkConnection();
        
        $data = $this->createCallObject('set');
        $data->key = $key;
        $data->val = $val;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    ///////////
    // list //
    public function listExists(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listExists');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listExpire(string $key, float $lifetime) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listExpire');
        $data->key = $key;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listAddFirst(string $key, mixed $val, float $lifetime = 0) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listAddFirst');
        $data->key = $key;
        $data->val = $val;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listAddLast(string $key, mixed $val, float $lifetime = 0) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listAddLast');
        $data->key = $key;
        $data->val = $val;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGet(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGet');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGetFirst(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGetFirst');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGetRemFirst(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGetRemFirst');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGetLast(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGetLast');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGetRemLast(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGetRemLast');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGetAllKeys() {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGetAllKeys');

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listGetRem(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listGetRem');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listRemove(string $key) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listRemove');
        $data->key = $key;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function listSet(string $key, mixed $val, float $lifetime = 0) {
        $this->checkConnection();
        
        $data = $this->createCallObject('listSet');
        $data->key = $key;
        $data->val = $val;
        $data->lifetime = $lifetime;

        $this->doCall($data);
        return $this->getResponse();
    }

    public function ping() {
        $this->checkConnection();
        
        $data = $this->createCallObject('ping');

        $this->doCall($data);
        return $this->getResponse();
    }
    
}