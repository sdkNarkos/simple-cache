<?php
declare(strict_types=1);

namespace sdkNarkos\SimpleCache\Client;

use sdkNarkos\SimpleCache\Protocol\CommandMessage;
use sdkNarkos\SimpleCache\Protocol\ResponseMessage;

class CacheClient {
    private readonly string $authKey;
    private readonly string $protocol;
    private readonly string $host;
    private readonly int $port;
    private readonly float $maxReadingDelay;
    private readonly int $connectTimeout;
    private readonly int $maxReconnectAttempts;

    /** @var callable|null */
    private $loggerCallback = null;

    private $socket;
    private bool $isConnected = false;
    private int $reconnectAttempts = 0;

    public function __construct(array $config) {
        if(!isset($config['authKey']) || !is_string($config['authKey']) || strlen($config['authKey']) < 1) {
            $this->log("error", "no authKey found");
            throw new \Exception("No authKey found");
        }
        $this->authKey = hash('sha256', $config['authKey']);

        $this->protocol = $config['protocol'] ?? 'tcp';
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 9999;
        $this->maxReadingDelay = $config['maxReadingDelay'] ?? 5;
        $this->connectTimeout = $config['connectTimeout'] ?? 30;
        $this->maxReconnectAttempts = $config['maxReconnectAttempts'] ?? 3;

        if (isset($config['logger']) && is_callable($config['logger'])) {
            $this->loggerCallback = $config['logger'];
        }
    }

    public function __destruct() {
        $this->disconnect();
    }

    public function isConnected(): bool {
        return $this->isConnected && $this->isSocketAlive();
    }

    private function log(string $level, string $message): void {
        if ($this->loggerCallback) {
            call_user_func($this->loggerCallback, $level, $message);
        } else {
            echo '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message . PHP_EOL;
        }
    }

    private function connect(): void {
        $url = $this->protocol . "://" . $this->host . ":" . $this->port;
        $errno = null;
        $errstr = '';

        $this->socket = stream_socket_client($url, $errno, $errstr, $this->connectTimeout);
        if (!$this->socket) {
            $this->log("error", "Connection failed: $errstr ($errno)");
            throw new \Exception("Connection failed: $errstr ($errno)");
        }

        if (!@stream_set_blocking($this->socket, false)) {
            $this->log("error", "Unable to set the stream to non-blocking mode");
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
        } else if ($this->reconnectAttempts > 0){
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
                $this->log('info', "Attempting to connect (attempt " . $this->reconnectAttempts + 1 . "})...");
                $this->connect();
                $this->log('info', "Connection successful.");
                $this->reconnectAttempts = 0;
                return;
            } catch (\Exception $e) {
                $this->reconnectAttempts++;
                $this->log('warning', "Connection attempt {$this->reconnectAttempts} failed: " . $e->getMessage());
                sleep(1 * $this->reconnectAttempts); // Linear backoff
            }
        }

        $this->log('error', "Failed to connect after {$this->maxReconnectAttempts} attempts");
        throw new \Exception("Failed to connect after {$this->maxReconnectAttempts} attempts");
    }

    private function doCall(CommandMessage $commandMessage): mixed {
        $jsonData = $commandMessage->toJson();
        $length = strlen($jsonData);
        $binLength = pack('N', $length);

        $written = @fwrite($this->socket, $binLength . $jsonData);
        if ($written === false) {
            $this->reconnect();
            $written = @fwrite($this->socket, $binLength . $jsonData);
            if ($written === false) {
                $this->log("error", "Error writing to the cache server after reconnect.");
                throw new \Exception("Error writing to the cache server after reconnect.");
            }
        }

        return $this->getResponse();
    }

    private function readBytes(int $length): string {
        $data = '';
        $start = microtime(true);
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                if ((microtime(true) - $start) >= $this->maxReadingDelay) {
                    $this->log("error", "Timeout reading from socket.");
                    throw new \Exception("Timeout reading from socket.");
                }
                usleep(1000);
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function getResponse(): mixed {
        // Read 4 octets to get message length
        $lengthData = $this->readBytes(4);
        $unpacked = unpack('Nlength', $lengthData);
        $targetLength = $unpacked['length'];

        // Read the entire JSON
        $responseData = $this->readBytes($targetLength);

        $responseMessage = ResponseMessage::createFromJson($responseData);

        if (null !== $responseMessage->getError()) {
            $this->log("error", $responseMessage->getError());
            throw new \Exception($responseMessage->getError());
        }

        return $responseMessage->getResults();
    }

    ///////////////
    // Commands //
    /////////////

    ////////////
    // value //
    public function exists(string $key): bool {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'exists', $key);
        return (bool)$this->doCall($commandMessage);
    }

    public function expire(string $key, float $ttl) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'expire', $key, null, $ttl);
        return $this->doCall($commandMessage);
    }

    public function get(string $key): mixed {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'get', $key);
        return $this->doCall($commandMessage);
    }

    public function getAllKeys() {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'getAllKeys');
        return $this->doCall($commandMessage);
    }

    public function getRem(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'getRem', $key);
        return $this->doCall($commandMessage);
    }

    public function remove(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'remove', $key);
        return $this->doCall($commandMessage);
    }

    public function set(string $key, string $val, float $ttl = 0) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'set', $key, $val, $ttl);
        return $this->doCall($commandMessage);
    }

    ///////////
    // list //
    public function listExists(string $key): bool {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listExists', $key);
        return (bool)$this->doCall($commandMessage);
    }

    public function listExpire(string $key, float $ttl) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listExpire', $key, null, $ttl);
        return $this->doCall($commandMessage);
    }

    public function listAddFirst(string $key, mixed $val, float $ttl = 0) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listAddFirst', $key, $val, $ttl);
        return $this->doCall($commandMessage);
    }

    public function listAddLast(string $key, mixed $val, float $ttl = 0) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listAddLast', $key, $val, $ttl);
        return $this->doCall($commandMessage);
    }

    public function listGet(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGet', $key);
        return $this->doCall($commandMessage);
    }

    public function listGetFirst(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGetFirst', $key);
        return $this->doCall($commandMessage);
    }

    public function listGetRemFirst(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGetRemFirst', $key);
        return $this->doCall($commandMessage);
    }

    public function listGetLast(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGetLast', $key);
        return $this->doCall($commandMessage);
    }

    public function listGetRemLast(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGetRemLast', $key);
        return $this->doCall($commandMessage);
    }

    public function listGetAllKeys() {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGetAllKeys');
        return $this->doCall($commandMessage);
    }

    public function listGetRem(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listGetRem', $key);
        return $this->doCall($commandMessage);
    }

    public function listRemove(string $key) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listRemove', $key);
        return $this->doCall($commandMessage);
    }

    public function listSet(string $key, mixed $val, float $ttl = 0) {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'listSet', $key, $val, $ttl);
        return $this->doCall($commandMessage);
    }

    ////////////
    // Others //
    public function ping(): string {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'ping');
        return (string)$this->doCall($commandMessage);
    }

    public function stats() {
        $this->checkConnection();
        $commandMessage = new CommandMessage($this->authKey, 'stats');
        return $this->doCall($commandMessage);
    }
    
}